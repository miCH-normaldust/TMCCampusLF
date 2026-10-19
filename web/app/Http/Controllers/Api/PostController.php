<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Jobs\ScorePostMatches;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageReencoder;
use App\Services\BlockedWordFilter;
use App\Services\TextMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'in:lost,found'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'location_id' => ['sometimes', 'integer', 'exists:locations,id'],
            'color' => ['sometimes', 'string', 'max:50'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:150'],
        ]);
        $posts = Post::query()
            ->with(['author:id,first_name', 'category:id,name', 'location:id,name', 'images', 'verificationQuestions'])
            ->whereIn('status', ['active', 'claim_pending', 'approved_for_meetup'])
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->when(isset($filters['color']), fn ($query) => $query->where('color', 'like', '%'.$filters['color'].'%'))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('event_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('event_date', '<=', $filters['to']))
            ->when(isset($filters['search']), function ($query) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $query->where(fn ($builder) => $builder
                    ->where('item_name', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->latest()
            ->paginate(15);

        return PostResource::collection($posts);
    }

    public function show(Post $post): PostResource
    {
        abort_unless(in_array($post->status, ['active', 'claim_pending', 'approved_for_meetup'], true), 404);

        return new PostResource($post->load([
            'author:id,first_name',
            'category:id,name',
            'location:id,name',
            'images',
            'verificationQuestions',
        ]));
    }

    public function similar(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:lost,found'],
            'item_name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'exists:categories,id'],
            'color' => ['required', 'string', 'max:50'],
            'location_id' => ['required', 'exists:locations,id'],
            'event_date' => ['required', 'date'],
        ]);
        $eventDate = Carbon::parse($data['event_date']);
        $matches = Post::with(['author:id,first_name', 'category:id,name', 'location:id,name', 'images', 'verificationQuestions'])
            ->where('type', $data['type'])
            ->whereIn('status', ['active', 'claim_pending', 'approved_for_meetup'])
            ->where('category_id', $data['category_id'])
            ->where('location_id', $data['location_id'])
            ->where('color', 'like', '%'.$data['color'].'%')
            ->whereBetween('event_date', [$eventDate->copy()->subDays(14), $eventDate->copy()->addDays(14)])
            ->where('item_name', 'like', '%'.$data['item_name'].'%')
            ->latest()
            ->limit(5)
            ->get();

        return PostResource::collection($matches);
    }

    public function matches(Request $request, Post $post)
    {
        abort_unless($post->user_id === $request->user()->id, 403);

        return PostResource::collection(
            Post::with(['author:id,first_name', 'category:id,name', 'location:id,name', 'images'])
                ->whereIn('id', $post->matches()->select('matched_post_id'))
                ->whereIn('status', ['active', 'claim_pending', 'approved_for_meetup'])
                ->orderByDesc(
                    Post::select('score')
                        ->from('post_matches')
                        ->whereColumn('post_matches.matched_post_id', 'posts.id')
                        ->where('post_matches.post_id', $post->id)
                        ->limit(1),
                )
                ->paginate(15),
        );
    }

    public function store(StorePostRequest $request, ImageReencoder $images, TextMasker $masker, BlockedWordFilter $blockedWords): JsonResponse
    {
        $user = $request->user();
        $dailyLimit = (int) DB::table('settings')->where('key', 'posts_per_day')->value('value') ?: 5;
        abort_if($user->posts()->whereDate('created_at', today())->count() >= $dailyLimit, 429, 'Daily post limit reached.');

        $data = $request->validated();
        $blockedWords->assertAllowed($data['item_name'], $data['description']);
        abort_unless(Category::whereKey($data['category_id'])->where('is_active', true)->exists(), 422, 'Category is unavailable.');
        abort_unless(Location::whereKey($data['location_id'])->where('is_active', true)->exists(), 422, 'Location is unavailable.');

        $data['item_name'] = $masker->mask($data['item_name']);
        $data['description'] = $masker->mask($data['description']);
        $data['expires_at'] = now()->addDays((int) (DB::table('settings')->where('key', 'archive_days')->value('value') ?: 30));

        $postData = array_intersect_key($data, array_flip([
            'type', 'item_name', 'description', 'category_id', 'color',
            'location_id', 'event_date', 'custody', 'expires_at',
        ]));
        $post = DB::transaction(function () use ($data, $postData, $user, $images, $request, $masker): Post {
            $post = $user->posts()->create($postData);

            foreach ($request->file('photos', []) as $photo) {
                $path = $images->store($photo['file']);
                $post->images()->create([
                    'path' => $path,
                    'alt_text' => $masker->mask($photo['alt_text']),
                ]);
            }

            foreach ($data['questions'] ?? [] as $question) {
                $post->verificationQuestions()->create([
                    'question' => $question['question'],
                    'answer' => mb_strtolower(trim($question['answer'])),
                ]);
            }

            return $post;
        });
        ScorePostMatches::dispatch($post->id)->afterCommit();

        return (new PostResource($post->load([
            'author:id,first_name',
            'category:id,name',
            'location:id,name',
            'images',
            'verificationQuestions',
        ])))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        $pendingReview = $post->claimRequests()->where('status', 'pending')->exists()
            || $post->sightings()->where('answer', 'yes')->where('admin_decision', 'pending')->exists();
        if ($pendingReview && ! $request->boolean('confirm_pending')) {
            return response()->json([
                'message' => 'There are pending requests. Confirm deletion to notify affected members.',
                'requires_confirmation' => true,
            ], 409);
        }

        if ($pendingReview) {
            $userIds = $post->claimRequests()->where('status', 'pending')->pluck('claimant_id')
                ->merge($post->sightings()->where('answer', 'yes')->where('admin_decision', 'pending')->pluck('user_id'))
                ->unique();
            foreach ($userIds as $userId) {
                User::find($userId)?->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'post_deleted',
                    'data' => ['title' => 'A post was removed', 'body' => 'A post you interacted with was deleted by its author.'],
                ]);
            }
        }

        foreach ($post->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        $post->delete();
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'event' => 'post_deleted',
            'ip_address' => $request->ip(),
            'device' => Str::limit((string) $request->userAgent(), 255, ''),
            'details' => ['post_id' => $post->id],
        ]);

        return response()->json(['message' => 'Post deleted.']);
    }

    public function update(StorePostRequest $request, Post $post, ImageReencoder $images, TextMasker $masker, BlockedWordFilter $blockedWords): PostResource
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        abort_unless($post->status === 'active', 409, 'Only active posts can be edited.');
        abort_if(
            $post->claimRequests()->whereIn('status', ['pending', 'approved'])->exists()
                || $post->sightings()->where('answer', 'yes')->whereIn('author_decision', ['pending', 'approved'])->exists(),
            409,
            'This post has a pending claim or evidence review and its details are locked.',
        );

        $data = $request->validated();
        abort_if($post->images()->count() + count($request->file('photos', [])) > 5, 422, 'A post can include at most five photos.');
        abort_unless(Category::whereKey($data['category_id'])->where('is_active', true)->exists(), 422, 'Category is unavailable.');
        abort_unless(Location::whereKey($data['location_id'])->where('is_active', true)->exists(), 422, 'Location is unavailable.');
        $postData = array_intersect_key($data, array_flip([
            'type', 'item_name', 'description', 'category_id', 'color',
            'location_id', 'event_date', 'custody',
        ]));
        $postData['item_name'] = $masker->mask($postData['item_name']);
        $postData['description'] = $masker->mask($postData['description']);

        DB::transaction(function () use ($data, $postData, $post, $request, $images, $masker): void {
            $post->update($postData);
            foreach ($request->file('photos', []) as $photo) {
                $post->images()->create([
                    'path' => $images->store($photo['file']),
                    'alt_text' => $masker->mask($photo['alt_text']),
                ]);
            }
            if ($post->type === 'found') {
                $post->verificationQuestions()->delete();
                foreach ($data['questions'] as $question) {
                    $post->verificationQuestions()->create([
                        'question' => $question['question'],
                        'answer' => mb_strtolower(trim($question['answer'])),
                    ]);
                }
            } else {
                $post->verificationQuestions()->delete();
            }
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'event' => 'post_updated',
                'ip_address' => $request->ip(),
                'device' => Str::limit((string) $request->userAgent(), 255, ''),
                'details' => ['post_id' => $post->id],
            ]);
        });

        return new PostResource($post->fresh()->load([
            'author:id,first_name',
            'category:id,name',
            'location:id,name',
            'images',
            'verificationQuestions',
        ]));
    }

    public function keepActive(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        abort_unless(in_array($post->status, ['active', 'archived'], true), 409, 'This post cannot be reactivated in its current state.');
        $archiveDays = (int) DB::table('settings')->where('key', 'archive_days')->value('value') ?: 30;
        abort_if($archiveDays > 60, 422, 'The configured archive period cannot exceed 60 days.');
        $post->update([
            'status' => 'active',
            'expires_at' => now()->addDays($archiveDays),
            'reminder_sent_at' => null,
        ]);

        return response()->json(['message' => 'Your post is active again.']);
    }
}
