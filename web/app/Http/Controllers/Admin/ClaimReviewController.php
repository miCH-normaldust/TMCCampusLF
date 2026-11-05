<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Conversation;
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

            if ($data['decision'] === 'approve') {
                $claim->update([
                    'status' => 'approved',
                    'author_decision' => 'pending',
                    'reviewed_by' => $request->user('admin')->id,
                    'reviewed_at' => now(),
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
                        'reviewed_by' => $request->user('admin')->id,
                        'reviewed_at' => now(),
                    ]);
                    $otherClaim->claimant->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'claim_rejected',
                        'data' => ['title' => 'Claim not approved', 'body' => 'Another claim for this item was approved.'],
                    ]);
                }
                $conversation = Conversation::firstOrCreate(['claim_id' => $claim->id]);
                $post->author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_approved',
                    'data' => [
                        'title' => 'Claim approved',
                        'body' => 'A claim for your found item was approved.',
                        'post_id' => $claim->post_id,
                        'claim_id' => $claim->id,
                    ],
                ]);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_approved',
                    'data' => [
                        'title' => 'Claim approved for owner review',
                        'body' => 'An administrator approved your claim. The item poster must agree before handover can proceed.',
                        'post_id' => $claim->post_id,
                        'claim_id' => $claim->id,
                    ],
                ]);
                $post->author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_owner_review',
                    'data' => [
                        'title' => 'A claim is ready for your decision',
                        'body' => 'An administrator approved a claim for '.$post->item_name.'. Confirm whether you agree to hand over the item.',
                        'claim_id' => $claim->id,
                        'post_id' => $claim->post_id,
                        'conversation_id' => $conversation->id,
                    ],
                ]);
            } else {
                $claim->update([
                    'status' => 'rejected',
                    'author_decision' => 'rejected',
                    'reviewed_by' => $request->user('admin')->id,
                    'reviewed_at' => now(),
                ]);
                $hasApprovedClaim = $post->claimRequests()
                    ->where('status', 'approved')
                    ->whereIn('author_decision', ['pending', 'approved'])
                    ->exists();
                $hasPendingClaims = $post->claimRequests()->where('status', 'pending')->exists();
                $post->update([
                    'status' => $hasApprovedClaim
                        ? 'handover_pending'
                        : ($hasPendingClaims ? 'claim_pending' : 'active'),
                ]);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_rejected',
                    'data' => ['title' => 'Claim not approved', 'body' => $data['reason']],
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
