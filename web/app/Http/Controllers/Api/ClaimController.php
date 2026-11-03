<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Handover;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimController extends Controller
{
    public function store(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->type === 'found' && in_array($post->status, ['active', 'claim_pending'], true), 409, 'This found item is not accepting claims.');
        abort_if($post->user_id === $request->user()->id, 403, 'You cannot claim your own post.');

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:3'],
            'answers.*' => ['required', 'string', 'max:1000'],
        ]);
        $questions = $post->verificationQuestions()->count();
        abort_if(count($data['answers']) !== $questions, 422, 'Answer every verification question.');

        $claim = DB::transaction(function () use ($request, $post, $data): ClaimRequest {
            $dailyLimit = (int) DB::table('settings')->where('key', 'claims_per_day')->value('value') ?: 5;
            abort_if(ClaimRequest::where('claimant_id', $request->user()->id)->whereDate('created_at', today())->count() >= $dailyLimit, 429, 'Daily claim limit reached.');
            $attempt = ClaimRequest::where('post_id', $post->id)
                ->where('claimant_id', $request->user()->id)
                ->count() + 1;
            $limit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
            abort_if($attempt > $limit, 429, 'The claim attempt limit has been reached.');
            abort_if(ClaimRequest::where('post_id', $post->id)
                ->where('claimant_id', $request->user()->id)
                ->where('status', 'pending')
                ->exists(), 409, 'You already have a claim waiting for review.');

            $claim = ClaimRequest::create([
                'post_id' => $post->id,
                'claimant_id' => $request->user()->id,
                'answers' => array_map(fn (string $answer): string => mb_strtolower(trim($answer)), $data['answers']),
                'attempt_no' => $attempt,
            ]);
            $post->update(['status' => 'claim_pending']);

            return $claim;
        });

        return response()->json([
            'message' => 'Your claim is waiting for administrator review.',
            'claim_id' => $claim->id,
            'attempt_no' => $claim->attempt_no,
        ], 201);
    }

    public function awaitingPostAuthor(Request $request): JsonResponse
    {
        $claims = ClaimRequest::with([
            'post:id,item_name,type,category_id',
            'post.category:id,name',
            'claimant:id,first_name,last_name',
        ])
            ->where('status', 'approved')
            ->where('author_decision', 'pending')
            ->whereHas('post', fn ($post) => $post->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(20)
            ->through(fn (ClaimRequest $claim): array => [
                'id' => $claim->id,
                'item_name' => $claim->post->item_name,
                'category' => $claim->post->category?->name ?? 'Other',
                'claimant_first_name' => $claim->claimant->first_name,
                'claimant_last_name' => $claim->claimant->last_name,
                'attempt_no' => $claim->attempt_no,
                'admin_reviewed_at' => $claim->reviewed_at,
            ]);

        return response()->json($claims);
    }

    public function decideForPostAuthor(Request $request, ClaimRequest $claim): JsonResponse
    {
        $claim->load(['post.author', 'claimant']);
        abort_unless($claim->post->user_id === $request->user()->id, 404);
        abort_unless(
            $claim->status === 'approved' && $claim->author_decision === 'pending',
            409,
            'This claim has already received the post author’s decision.',
        );

        $decision = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ])['decision'];

        DB::transaction(function () use ($claim, $decision): void {
            $claim->update(['author_decision' => $decision === 'approve' ? 'approved' : 'rejected']);

            if ($decision === 'approve') {
                Handover::firstOrCreate(['claim_id' => $claim->id]);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_owner_approved',
                    'data' => [
                        'title' => 'The item poster agreed',
                        'body' => 'The item poster agreed to your approved claim. Open Inbox > Handovers to confirm the exchange.',
                        'claim_id' => $claim->id,
                    ],
                ]);

                return;
            }

            if (! $claim->post->claimRequests()
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($approved): void {
                            $approved->where('status', 'approved')
                                ->whereIn('author_decision', ['pending', 'approved']);
                        });
                })
                ->exists()) {
                $claim->post->update(['status' => 'active']);
            }

            $claim->claimant->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'claim_owner_rejected',
                'data' => [
                    'title' => 'The item poster declined the handover',
                    'body' => 'The item poster did not agree to proceed with this claim.',
                    'claim_id' => $claim->id,
                ],
            ]);
        });

        return response()->json([
            'message' => $decision === 'approve'
                ? 'You agreed to the claim. Both participants can now confirm the handover.'
                : 'Your decision was recorded.',
            'decision' => $decision,
        ]);
    }
}
