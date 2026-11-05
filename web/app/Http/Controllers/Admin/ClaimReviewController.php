<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClaimReviewController extends Controller
{
    public function show(ClaimRequest $claim): View
    {
        return view('admin.claim', [
            'claim' => $claim->load([
                'post.author:id,first_name,last_name',
                'post.category:id,name',
                'post.location:id,name',
                'post.verificationQuestions',
                'claimant:id,first_name,last_name,tmc_id_no,email',
                'evidenceImages',
            ]),
        ]);
    }

    public function decide(Request $request, ClaimRequest $claim): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $claim, $data): void {
            $post = Post::whereKey($claim->post_id)->lockForUpdate()->firstOrFail();
            $claim = ClaimRequest::with(['claimant', 'post.author'])
                ->whereKey($claim->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($claim->status === 'pending', 409, 'This claim has already been reviewed.');
            abort_unless(
                $claim->author_decision === 'approved',
                409,
                'The post owner must approve this claim before administrator review.',
            );

            if ($data['decision'] === 'approve') {
                $claim->update([
                    'status' => 'approved',
                    'reviewed_by' => $request->user('admin')->id,
                    'reviewed_at' => now(),
                    'review_reason' => null,
                ]);
                $post->update(['status' => 'handover_pending']);
                $otherClaims = ClaimRequest::with('claimant')
                    ->where('post_id', $claim->post_id)
                    ->where('id', '!=', $claim->id)
                    ->where('status', 'pending')
                    ->get();
                foreach ($otherClaims as $otherClaim) {
                    $otherClaim->update([
                        'status' => 'rejected',
                        'author_decision' => 'rejected',
                        'author_decision_reason' => 'Another claim was approved for this item.',
                        'reviewed_by' => $request->user('admin')->id,
                        'reviewed_at' => now(),
                        'review_reason' => 'Another claim was approved for this item.',
                    ]);
                    $otherClaim->claimant->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'claim_rejected',
                        'data' => [
                            'title' => 'Claim not approved',
                            'body' => 'Another claim for this item was approved.',
                            'claim_id' => $otherClaim->id,
                        ],
                    ]);
                }
                $conversation = Conversation::firstOrCreate(['claim_id' => $claim->id]);
                Handover::firstOrCreate(
                    ['claim_id' => $claim->id],
                    ['status' => 'awaiting_participants', 'admin_approved_at' => now()],
                );
                $post->author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_approved',
                    'data' => [
                        'title' => 'Claim approved',
                        'body' => 'TMC administrators approved the claim you agreed to. The handover can now proceed.',
                        'post_id' => $claim->post_id,
                        'claim_id' => $claim->id,
                    ],
                ]);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_approved',
                    'data' => [
                        'title' => 'Claim approved',
                        'body' => 'TMC administrators approved your claim. Open Inbox > Handovers to confirm the exchange.',
                        'post_id' => $claim->post_id,
                        'claim_id' => $claim->id,
                    ],
                ]);
            } else {
                $claim->update([
                    'status' => 'rejected',
                    'reviewed_by' => $request->user('admin')->id,
                    'reviewed_at' => now(),
                    'review_reason' => $data['reason'],
                ]);
                $hasPendingClaims = $post->claimRequests()->where('status', 'pending')->exists();
                $post->update(['status' => $hasPendingClaims ? 'claim_pending' : 'active']);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_rejected',
                    'data' => [
                        'title' => 'Claim not approved',
                        'body' => $data['reason'],
                        'claim_id' => $claim->id,
                        'reason' => $data['reason'],
                    ],
                ]);
            }

            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'claim_'.$data['decision'],
                'target_type' => ClaimRequest::class,
                'target_id' => $claim->id,
                'reason' => $data['reason'] ?? null,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('admin.dashboard')->with('status', 'Claim review saved.');
    }
}
