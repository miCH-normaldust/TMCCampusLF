<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Conversation;
use App\Models\Handover;
use App\Models\Post;
use App\Services\ImageReencoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClaimController extends Controller
{
    public function store(Request $request, Post $post, ImageReencoder $images): JsonResponse
    {
        abort_unless($post->type === 'found' && in_array($post->status, ['active', 'claim_pending'], true), 409, 'This found item is not accepting claims.');
        abort_if($post->user_id === $request->user()->id, 403, 'You cannot claim your own post.');

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:3'],
            'answers.*' => ['required', 'string', 'max:1000'],
            'evidence_images' => ['nullable', 'array', 'max:5'],
            'evidence_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $questions = $post->verificationQuestions()->count();
        abort_if(count($data['answers']) !== $questions, 422, 'Answer every verification question.');

        $claim = DB::transaction(function () use ($request, $post, $data, $images): ClaimRequest {
            $lockedPost = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $lockedPost->type === 'found' && in_array($lockedPost->status, ['active', 'claim_pending'], true),
                409,
                'This found item is not accepting claims.',
            );
            $userClaims = $lockedPost->claimRequests()
                ->where('claimant_id', $request->user()->id)
                ->latest()
                ->get();

            $latestClaim = $userClaims->first();
            $handoverUnsuccessful = $latestClaim
                && Handover::where('claim_id', $latestClaim->id)
                    ->where('status', 'unsuccessful')
                    ->exists();

            if ($latestClaim && $latestClaim->status === 'pending') {
                abort(409, 'Your claim is under review. You cannot submit another claim until it is rejected.');
            }

            if (
                $latestClaim
                && ! $handoverUnsuccessful
                && $latestClaim->status === 'approved'
                && in_array($latestClaim->author_decision, ['pending', 'approved'], true)
            ) {
                abort(409, 'You already have an active approved claim for this post.');
            }

            $attemptLimit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
            $attemptsUsed = $userClaims->count();
            abort_if(
                $attemptsUsed >= $attemptLimit,
                429,
                "You have used all {$attemptLimit} claim attempts for this item.",
            );
            $isRejected = $latestClaim
                && (
                    $latestClaim->status === 'rejected'
                    || $latestClaim->author_decision === 'rejected'
                    || $handoverUnsuccessful
                );
            abort_if(
                $latestClaim && ! $isRejected,
                409,
                'Your previous claim is still active and cannot be submitted again.',
            );
            abort_if(
                empty($data['evidence_images']),
                422,
                'Upload at least one photo showing that you own this item.',
            );

            $dailyLimit = (int) DB::table('settings')->where('key', 'claims_per_day')->value('value') ?: 5;
            abort_if(ClaimRequest::where('claimant_id', $request->user()->id)->whereDate('created_at', today())->count() >= $dailyLimit, 429, 'Daily claim limit reached.');

            $attemptNo = $userClaims->count() + 1;
            $claim = $lockedPost->claimRequests()->create([
                'claimant_id' => $request->user()->id,
                'answers' => array_map(fn (string $answer): string => mb_strtolower(trim($answer)), $data['answers']),
                'attempt_no' => $attemptNo,
                'status' => 'pending',
                'author_decision' => 'pending',
            ]);
            foreach ($data['evidence_images'] as $image) {
                $claim->evidenceImages()->create(['path' => $images->store($image)]);
            }
            $lockedPost->update(['status' => 'claim_pending']);
            $lockedPost->author->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'claim_pending',
                'data' => [
                    'title' => 'A found-item claim needs your decision',
                    'body' => 'A claim for '.$lockedPost->item_name.' is waiting for your review.',
                    'post_id' => $lockedPost->id,
                    'claim_id' => $claim->id,
                ],
            ]);

            return $claim->fresh();
        });

        $attemptLimit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;

        return response()->json([
            'message' => 'Your claim is waiting for the post owner’s decision.',
            'claim_id' => $claim->id,
            'attempt_no' => $claim->attempt_no,
            'attempts_used' => $claim->attempt_no,
            'attempt_limit' => $attemptLimit,
            'updated' => false,
            'editable' => true,
            'can_submit_another' => false,
        ], 201);
    }

    public function update(Request $request, Post $post, ClaimRequest $claim, ImageReencoder $images): JsonResponse
    {
        abort_unless($claim->post_id === $post->id, 404);
        abort_unless($claim->claimant_id === $request->user()->id, 404);

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:3'],
            'answers.*' => ['required', 'string', 'max:1000'],
            'evidence_images' => ['nullable', 'array', 'max:5'],
            'evidence_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        abort_if(
            count($data['answers']) !== $post->verificationQuestions()->count(),
            422,
            'Answer every verification question.',
        );

        $oldImagePaths = [];
        $claim = DB::transaction(function () use ($request, $post, $claim, $data, $images, &$oldImagePaths): ClaimRequest {
            $claim = $post->claimRequests()
                ->whereKey($claim->id)
                ->where('claimant_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $claim->status === 'pending' && $claim->author_decision === 'pending',
                409,
                'Claims can only be edited before the post owner decides.',
            );

            $claim->update([
                'answers' => array_map(fn (string $answer): string => mb_strtolower(trim($answer)), $data['answers']),
            ]);

            if (! empty($data['evidence_images'])) {
                $oldImagePaths = $claim->evidenceImages()->pluck('path')->all();
                $claim->evidenceImages()->delete();
                foreach ($data['evidence_images'] as $image) {
                    $claim->evidenceImages()->create(['path' => $images->store($image)]);
                }
            }

            return $claim->fresh();
        });

        if ($oldImagePaths !== []) {
            Storage::disk('public')->delete($oldImagePaths);
        }

        return response()->json([
            'message' => 'Your claim changes were saved.',
            'claim_id' => $claim->id,
            'attempt_no' => $claim->attempt_no,
            'updated' => true,
            'editable' => true,
        ]);
    }

    public function myClaim(Request $request, Post $post): JsonResponse
    {
        $userClaims = $post->claimRequests()
            ->where('claimant_id', $request->user()->id)
            ->latest()
            ->get();

        $claim = $userClaims->first();

        if (! $claim) {
            return response()->json(['data' => null]);
        }

        $handover = Handover::where('claim_id', $claim->id)
            ->where('status', 'unsuccessful')
            ->first();
        $handoverUnsuccessful = $handover !== null;
        $isRejected = $claim->status === 'rejected'
            || $claim->author_decision === 'rejected'
            || $handoverUnsuccessful;
        $attemptLimit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
        $canReapply = $isRejected
            && $userClaims->count() < $attemptLimit
            && in_array($post->status, ['active', 'claim_pending'], true);
        $canSubmitAnother = $canReapply;

        return response()->json(['data' => [
            'id' => $claim->id,
            'answers' => $claim->answers,
            'evidence_images' => $claim->evidenceImages
                ->map(fn ($image): string => \App\Support\PublicMediaUrl::forPath($image->path))
                ->all(),
            'status' => $claim->status,
            'author_decision' => $claim->author_decision,
            'author_decision_reason' => $claim->author_decision_reason,
            'review_reason' => $claim->review_reason,
            'handover_unsuccessful' => $handoverUnsuccessful,
            'handover_failure_reason' => $handover?->failure_reason,
            'created_at' => $claim->created_at,
            'editable_until' => null,
            'retry_available_at' => null,
            'editable' => $claim->status === 'pending' && $claim->author_decision === 'pending',
            'is_rejected' => $isRejected,
            'attempts_used' => $userClaims->count(),
            'attempt_limit' => $attemptLimit,
            'can_reapply' => $canReapply,
            'can_submit_another' => $canSubmitAnother,
        ]]);
    }

    public function awaitingPostAuthor(Request $request): JsonResponse
    {
        $claims = ClaimRequest::with([
            'post:id,item_name,type,category_id',
            'post.category:id,name',
            'post.verificationQuestions:id,post_id,question,answer',
            'claimant:id,first_name,last_name',
            'evidenceImages',
        ])
            ->whereIn('status', ['pending', 'approved'])
            ->where('author_decision', 'pending')
            ->whereHas('post', fn ($post) => $post->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(20)
            ->through(fn (ClaimRequest $claim): array => [
                'id' => $claim->id,
                'post_id' => $claim->post_id,
                'item_name' => $claim->post->item_name,
                'category' => $claim->post->category?->name ?? 'Other',
                'claimant_first_name' => $claim->claimant->first_name,
                'claimant_last_name' => $claim->claimant->last_name,
                'attempt_no' => $claim->attempt_no,
                'answers' => $claim->answers,
                'verification_questions' => $claim->post->verificationQuestions
                    ->map(fn ($question): array => [
                        'question' => $question->question,
                        'answer' => $question->answer,
                    ])
                    ->all(),
                'evidence_images' => $claim->evidenceImages
                    ->map(fn ($image): string => \App\Support\PublicMediaUrl::forPath($image->path))
                    ->all(),
            ]);

        return response()->json($claims);
    }

    public function decideForPostAuthor(Request $request, ClaimRequest $claim): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $decision = $data['decision'];
        DB::transaction(function () use ($request, $claim, $data, $decision): void {
            $post = Post::whereKey($claim->post_id)->lockForUpdate()->firstOrFail();
            $claim = ClaimRequest::with('claimant')
                ->whereKey($claim->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($post->user_id === $request->user()->id, 404);
            abort_unless(
                in_array($claim->status, ['pending', 'approved'], true)
                    && $claim->author_decision === 'pending',
                409,
                'This claim has already received the post author’s decision.',
            );
            $decision = $data['decision'];

            if ($decision === 'approve') {
                $claim->update([
                    'author_decision' => 'approved',
                    'author_decision_reason' => $data['reason'],
                ]);
                $conversationId = null;
                if ($claim->status === 'approved') {
                    $conversation = Conversation::firstOrCreate(['claim_id' => $claim->id]);
                    Handover::firstOrCreate(
                        ['claim_id' => $claim->id],
                        ['status' => 'awaiting_participants', 'admin_approved_at' => now()],
                    );
                    $post->update(['status' => 'handover_pending']);
                    $conversationId = $conversation->id;
                }
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_owner_approved',
                    'data' => [
                        'title' => 'The item poster agreed',
                        'body' => $conversationId === null
                            ? 'The item poster agreed to your claim. It is now waiting for TMC administrator review.'
                            : 'The item poster agreed to your claim. Open Inbox > Handovers to confirm the exchange.',
                        'claim_id' => $claim->id,
                        'post_id' => $claim->post_id,
                        'conversation_id' => $conversationId,
                        'reason' => $data['reason'],
                    ],
                ]);

                return;
            }

            $claim->update([
                'status' => 'rejected',
                'author_decision' => 'rejected',
                'author_decision_reason' => $data['reason'],
            ]);
            if (! $post->claimRequests()->where('status', 'pending')->exists()) {
                $post->update(['status' => 'active']);
            }

            $claim->claimant->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'claim_owner_rejected',
                'data' => [
                    'title' => 'The item poster declined your claim',
                    'body' => $data['reason'],
                    'claim_id' => $claim->id,
                    'reason' => $data['reason'],
                ],
            ]);
        });

        return response()->json([
            'message' => $decision === 'approve'
                ? 'You agreed to the claim. It is now waiting for TMC administrator review.'
                : 'You declined the claim and your reason was sent to the claimant.',
            'decision' => $decision,
        ]);
    }
}
