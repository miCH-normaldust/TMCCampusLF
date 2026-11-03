<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostRetrievalRequestController extends Controller
{
    public function decide(Request $request, PostRetrievalRequest $retrievalRequest): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $retrievalRequest, $data): void {
            $lockedRequest = PostRetrievalRequest::with('user')
                ->whereKey($retrievalRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedRequest->status === 'pending', 409, 'This retrieval request has already been reviewed.');

            $approved = $data['decision'] === 'approve';
            $post = Post::whereKey($lockedRequest->post_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($approved) {
                abort_unless(
                    in_array($post->type, ['lost', 'found'], true)
                        && in_array($post->status, ['active', 'archived'], true),
                    409,
                    'This post is no longer eligible to be marked retrieved.',
                );
                abort_if(
                    $post->claimRequests()->whereIn('status', ['pending', 'approved'])->exists()
                        || $post->sightings()
                            ->where('answer', 'yes')
                            ->where(function ($query): void {
                                $query->whereIn('author_decision', ['pending', 'approved'])
                                    ->orWhereIn('admin_decision', ['pending', 'approved']);
                            })
                            ->exists(),
                    409,
                    'This post now has a claim or sighting being reviewed.',
                );
            }
            $lockedRequest->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by' => $request->user('admin')->id,
                'reviewed_at' => now(),
            ]);
            if ($approved) {
                $post->update(['status' => 'retrieved']);
            }

            $lockedRequest->user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $approved ? 'post_retrieval_approved' : 'post_retrieval_rejected',
                'data' => [
                    'title' => $approved ? 'Post marked as retrieved' : 'Retrieval request not approved',
                    'body' => $approved
                        ? 'TMC administrators approved your request. "'.$post->item_name.'" is now marked as retrieved.'
                        : ($data['reason'] ?? 'TMC administrators did not approve this retrieval request.'),
                    'post_id' => $post->id,
                ],
            ]);

            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'post_retrieval_'.$data['decision'],
                'target_type' => PostRetrievalRequest::class,
                'target_id' => $lockedRequest->id,
                'reason' => $data['reason'] ?? null,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Retrieval request decision saved.');
    }
}
