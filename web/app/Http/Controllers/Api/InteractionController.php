<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\LostSighting;
use App\Models\Post;
use App\Models\User;
use App\Services\ImageReencoder;
use App\Services\TextMasker;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InteractionController extends Controller
{
    public function comments(Post $post): JsonResponse
    {
        $comments = $post->comments()
            ->whereNull('parent_id')
            ->with([
                'author:id,first_name,last_name,profile_picture_path',
                'replies.author:id,first_name,last_name,profile_picture_path',
            ])
            ->oldest()
            ->paginate(30)
            ->through(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author_id' => $comment->user_id,
                'author_first_name' => $comment->author->first_name,
                'author_last_name' => $comment->author->last_name,
                'author_profile_picture_url' => $comment->author->profile_picture_path
                    ? PublicMediaUrl::forPath($comment->author->profile_picture_path)
                    : null,
                'created_at' => $comment->created_at,
                'parent_id' => null,
                'replies' => $comment->replies->map(fn (Comment $reply): array => [
                    'id' => $reply->id,
                    'body' => $reply->body,
                    'author_id' => $reply->user_id,
                    'author_first_name' => $reply->author->first_name,
                    'author_last_name' => $reply->author->last_name,
                    'author_profile_picture_url' => $reply->author->profile_picture_path
                        ? PublicMediaUrl::forPath($reply->author->profile_picture_path)
                        : null,
                    'created_at' => $reply->created_at,
                    'parent_id' => $reply->parent_id,
                    'replies' => [],
                ])->all(),
            ]);

        return response()->json($comments);
    }

    public function comment(Request $request, Post $post, TextMasker $masker): JsonResponse
    {
        abort_unless(in_array($post->status, ['active', 'claim_pending', 'handover_pending'], true), 404);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ]);
        if (isset($data['parent_id'])) {
            abort_unless(
                $post->comments()->whereKey($data['parent_id'])->whereNull('parent_id')->exists(),
                422,
                'Replies can only be added to a top-level comment on this post.',
            );
        }
        $limit = (int) DB::table('settings')->where('key', 'comments_per_day')->value('value') ?: 30;
        abort_if($request->user()->comments()->whereDate('created_at', today())->count() >= $limit, 429, 'Daily comment limit reached.');
        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $masker->mask($data['body']),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'author_first_name' => $request->user()->first_name,
            'created_at' => $comment->created_at,
        ], 201);
    }

    public function deleteComment(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);
        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function react(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:like,none']]);
        if ($data['type'] === 'none') {
            $post->reactions()->where('user_id', $request->user()->id)->delete();

            return response()->json([
                'reaction' => null,
                'reactions_count' => $post->reactions()->count(),
            ]);
        }

        $reaction = $post->reactions()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['type' => $data['type']],
        );

        return response()->json([
            'reaction' => 'like',
            'reactions_count' => $post->reactions()->count(),
        ]);
    }

    public function report(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $limit = (int) DB::table('settings')->where('key', 'reports_per_day')->value('value') ?: 5;
        abort_if($request->user()->reportRecords()->whereDate('created_at', today())->count() >= $limit, 429, 'Daily report limit reached.');
        abort_if(
            $request->user()->reportRecords()->where('post_id', $post->id)->exists(),
            409,
            'You have already reported this post.',
        );

        DB::transaction(function () use ($request, $post, $data): void {
            $inserted = DB::table('reports')->insertOrIgnore([
                'post_id' => $post->id,
                'reporter_id' => $request->user()->id,
                'reason' => $data['reason'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            abort_if($inserted === 0, 409, 'You have already reported this post.');

            $threshold = (int) DB::table('settings')->where('key', 'report_threshold')->value('value') ?: 3;
            $reports = DB::table('reports')->where('post_id', $post->id)->where('status', 'pending')->count();
            if ($reports >= $threshold && $post->status !== 'hidden') {
                $post->update(['status' => 'hidden', 'hidden_reason' => 'Automatically hidden after reaching the report threshold.']);
                $post->author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'post_hidden',
                    'data' => [
                        'title' => 'Your post is under review',
                        'body' => 'Your post was hidden after receiving multiple reports and is awaiting TMC administrator review.',
                        'post_id' => $post->id,
                    ],
                ]);
            }
        });

        return response()->json(['message' => 'Report sent for administrator review.'], 201);
    }

    public function sighting(Request $request, Post $post, ImageReencoder $images, TextMasker $masker): JsonResponse
    {
        abort_unless($post->type === 'lost' && in_array($post->status, ['active', 'claim_pending'], true), 409, 'This lost post is not accepting sightings.');
        abort_unless($post->user_id !== $request->user()->id, 403, 'You cannot submit a sighting for your own post.');
        $data = $request->validate([
            'answer' => ['required', 'in:yes,no'],
            'evidence_text' => ['required_if:answer,yes', 'nullable', 'string', 'max:2000'],
            'evidence_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $result = DB::transaction(function () use ($request, $post, $data, $images, $masker): array {
            $lockedPost = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($data['answer'] !== 'yes') {
                $sighting = $lockedPost->sightings()->create([
                    'user_id' => $request->user()->id,
                    'answer' => 'no',
                ]);

                return [$sighting, false];
            }

            $userSightings = $lockedPost->sightings()
                ->where('user_id', $request->user()->id)
                ->where('answer', 'yes')
                ->latest()
                ->get();

            $latestSighting = $userSightings->first();

            if ($latestSighting && $latestSighting->author_decision === 'pending' && $latestSighting->admin_decision === 'pending') {
                abort_unless(
                    $latestSighting->created_at->gt(now()->subHour()),
                    409,
                    'Your evidence is under review and can only be edited during the first hour.',
                );

                $imagePath = isset($data['evidence_image'])
                    ? $images->store($data['evidence_image'])
                    : $latestSighting->evidence_image;

                $latestSighting->update([
                    'evidence_text' => isset($data['evidence_text']) ? $masker->mask($data['evidence_text']) : null,
                    'evidence_image' => $imagePath,
                ]);

                return [$latestSighting->fresh(), true];
            }

            if ($latestSighting && ($latestSighting->author_decision === 'approved' || $latestSighting->admin_decision === 'approved')) {
                abort(409, 'Your evidence has already been approved.');
            }

            $attemptLimit = (int) DB::table('settings')->where('key', 'proof_attempts')->value('value') ?: 3;
            abort_if(
                $userSightings->count() >= $attemptLimit,
                429,
                "You have used all {$attemptLimit} evidence attempts for this item.",
            );

            abort_if(! isset($data['evidence_image']) && $latestSighting?->evidence_image === null, 422, 'An evidence photo is required.');

            $imagePath = isset($data['evidence_image'])
                ? $images->store($data['evidence_image'])
                : $latestSighting?->evidence_image;

            $sighting = $lockedPost->sightings()->create([
                'user_id' => $request->user()->id,
                'answer' => 'yes',
                'evidence_text' => isset($data['evidence_text']) ? $masker->mask($data['evidence_text']) : null,
                'evidence_image' => $imagePath,
                'author_decision' => 'pending',
                'admin_decision' => 'pending',
            ]);

            return [$sighting->fresh(), false];
        });
        [$sighting, $updated] = $result;
        if ($data['answer'] === 'yes' && ! $updated) {
            $post->author->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'lost_sighting',
                'data' => [
                    'title' => 'Someone sent evidence for your lost item',
                    'body' => 'Review the evidence from Inbox > Evidence.',
                    'post_id' => $post->id,
                    'sighting_id' => $sighting->id,
                ],
            ]);
            foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
                $admin->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'sighting_submitted',
                    'data' => [
                        'title' => 'Lost-item evidence submitted',
                        'body' => 'The post owner must review this evidence before administrator review.',
                        'post_id' => $post->id,
                        'sighting_id' => $sighting->id,
                    ],
                ]);
            }
        }

        return response()->json([
            'message' => $data['answer'] === 'yes' ? 'Evidence sent to the post author for review.' : 'Your answer was recorded.',
            'sighting_id' => $sighting->id,
            'updated' => $updated,
            'editable' => $data['answer'] === 'yes',
        ], $updated ? 200 : 201);
    }

    public function mySighting(Request $request, Post $post): JsonResponse
    {
        $userSightings = $post->sightings()
            ->where('user_id', $request->user()->id)
            ->where('answer', 'yes')
            ->latest()
            ->get();

        $sighting = $userSightings->first();

        if (! $sighting) {
            return response()->json(['data' => null]);
        }

        $isRejected = $sighting->author_decision === 'rejected' || $sighting->admin_decision === 'rejected';
        $attemptLimit = (int) DB::table('settings')->where('key', 'proof_attempts')->value('value') ?: 3;
        $canReapply = $isRejected && $userSightings->count() < $attemptLimit;

        return response()->json(['data' => [
            'id' => $sighting->id,
            'evidence_text' => $sighting->evidence_text,
            'evidence_image' => $sighting->evidence_image
                ? PublicMediaUrl::forPath($sighting->evidence_image)
                : null,
            'author_decision' => $sighting->author_decision,
            'admin_decision' => $sighting->admin_decision,
            'admin_reason' => $sighting->admin_reason,
            'editable_until' => $sighting->created_at->copy()->addHour(),
            'editable' => $sighting->author_decision === 'pending'
                && $sighting->admin_decision === 'pending'
                && $sighting->created_at->gt(now()->subHour()),
            'is_rejected' => $isRejected,
            'attempts_used' => $userSightings->count(),
            'attempt_limit' => $attemptLimit,
            'can_reapply' => $canReapply,
        ]]);
    }

    public function myEvidence(Request $request): JsonResponse
    {
        $evidence = LostSighting::with([
            'post:id,item_name,type,user_id',
            'post.author:id,first_name,last_name',
            'user:id,first_name,last_name',
            'conversation:id,sighting_id',
        ])
            ->where('answer', 'yes')
            ->whereHas('post', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(25)
            ->through(fn (LostSighting $sighting): array => [
                'id' => $sighting->id,
                'post_id' => $sighting->post_id,
                'item_name' => $sighting->post->item_name,
                'finder_first_name' => $sighting->user->first_name,
                'finder_last_name' => $sighting->user->last_name,
                'evidence_text' => $sighting->evidence_text,
                'evidence_image' => $sighting->evidence_image
                    ? PublicMediaUrl::forPath($sighting->evidence_image)
                    : null,
                'author_decision' => $sighting->author_decision,
                'admin_decision' => $sighting->admin_decision,
                'admin_reason' => $sighting->admin_reason,
                'conversation_id' => $sighting->conversation?->id,
                'created_at' => $sighting->created_at,
            ]);

        return response()->json($evidence);
    }

    public function sightings(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 403);

        return response()->json([
            'data' => $post->sightings()->with(['user:id,first_name', 'handover:id,sighting_id'])
                ->where('answer', 'yes')
                ->latest()
                ->get()
                ->map(fn (LostSighting $sighting): array => [
                    'id' => $sighting->id,
                    'user_first_name' => $sighting->user->first_name,
                    'evidence_text' => $sighting->evidence_text,
                    'evidence_image' => $sighting->evidence_image ? PublicMediaUrl::forPath($sighting->evidence_image) : null,
                    'author_decision' => $sighting->author_decision,
                    'admin_decision' => $sighting->admin_decision,
                    'admin_reason' => $sighting->admin_reason,
                    'handover_id' => $sighting->handover?->id,
                    'created_at' => $sighting->created_at,
                ]),
        ]);
    }

    public function decideSighting(Request $request, LostSighting $sighting): JsonResponse
    {
        $sighting->load('post');
        abort_unless($sighting->post->user_id === $request->user()->id, 403);
        abort_unless($sighting->answer === 'yes' && $sighting->author_decision === 'pending', 409, 'This evidence has already been reviewed.');
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $sighting->update(['author_decision' => $data['decision'] === 'approve' ? 'approved' : 'rejected']);
        $admins = User::where('role', 'admin')->where('status', 'active')->get();
        foreach ($admins as $admin) {
            $admin->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'sighting_review',
                'data' => [
                    'title' => 'Lost-item evidence needs review',
                    'body' => $data['decision'] === 'approve'
                        ? 'The post author approved a sighting for '.$sighting->post->item_name.'.'
                        : 'The post author rejected a sighting.',
                    'sighting_id' => $sighting->id,
                    'post_id' => $sighting->post_id,
                ],
            ]);
        }
        $sighting->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'sighting_owner_reviewed',
            'data' => [
                'title' => $data['decision'] === 'approve'
                    ? 'The post owner approved your evidence'
                    : 'The post owner did not approve your evidence',
                'body' => $data['decision'] === 'approve'
                    ? 'Your evidence is now waiting for TMC administrator review.'
                    : 'The post owner reviewed your evidence.',
                'sighting_id' => $sighting->id,
                'post_id' => $sighting->post_id,
            ],
        ]);

        return response()->json(['message' => 'Your evidence review was recorded.']);
    }
}
