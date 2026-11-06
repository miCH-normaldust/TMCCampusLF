<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostRetrievalRequestController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $retrievals = PostRetrievalRequest::with([
            'post:id,item_name,type,user_id',
        ])
            ->where('user_id', $request->user()->id)
            ->where('status', 'approved')
            ->whereHas('post', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('reviewed_at')
            ->paginate(25)
            ->through(fn (PostRetrievalRequest $retrieval): array => [
                'id' => $retrieval->id,
                'history_kind' => 'retrieval_request',
                'transaction_reference' => 'RR-'.str_pad((string) $retrieval->id, 8, '0', STR_PAD_LEFT),
                'type' => $retrieval->post->type,
                'item_name' => $retrieval->post->item_name,
                'other_first_name' => 'TMC administrators',
                'status' => 'completed',
                'completed' => true,
                'history_at' => $retrieval->reviewed_at,
            ]);

        return response()->json($retrievals);
    }

    public function store(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $retrievalRequest = DB::transaction(function () use ($post, $request, $data): PostRetrievalRequest {
            $lockedPost = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array($lockedPost->status, ['active', 'archived'], true),
                409,
                'This post is not eligible for a retrieval request.',
            );
            abort_if(
                $lockedPost->claimRequests()->whereIn('status', ['pending', 'approved'])->exists()
                    || $lockedPost->sightings()
                        ->where('answer', 'yes')
                        ->where(function ($query): void {
                            $query->whereIn('author_decision', ['pending', 'approved'])
                                ->orWhereIn('admin_decision', ['pending', 'approved']);
                        })
                        ->exists(),
                409,
                'This post has a claim or sighting being reviewed and cannot be marked retrieved yet.',
            );
            abort_if(
                $lockedPost->retrievalRequests()->where('status', 'pending')->exists(),
                409,
                'A retrieval request for this post is already waiting for review.',
            );

            $retrievalRequest = $lockedPost->retrievalRequests()->create([
                'user_id' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
            ]);

            foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
                $admin->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'post_retrieval_requested',
                    'data' => [
                        'title' => 'Post retrieval approval requested',
                        'body' => $request->user()->first_name.' requested approval to mark "'.$lockedPost->item_name.'" as retrieved.',
                        'retrieval_request_id' => $retrievalRequest->id,
                        'post_type' => $lockedPost->type,
                    ],
                ]);
            }

            return $retrievalRequest;
        });

        return response()->json([
            'message' => 'Your request was sent to TMC administrators for approval.',
            'retrieval_request_id' => $retrievalRequest->id,
            'status' => $retrievalRequest->status,
        ], 201);
    }
}
