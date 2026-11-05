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

        [$claim, $updated] = DB::transaction(function () use ($request, $post, $data, $images): array {
            $lockedPost = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $userClaims = $lockedPost->claimRequests()
                ->where('claimant_id', $request->user()->id)
                ->latest()
                ->get();

            $latestClaim = $userClaims->first();

            if ($latestClaim && $latestClaim->status === 'pending') {
                abort_unless(
                    $latestClaim->created_at->gt(now()->subHour()),
                    409,
                    'Your claim is currently under review and can only be edited during the first hour.',
                );
                $latestClaim->update([
                    'answers' => array_map(fn (string $answer): string => mb_strtolower(trim($answer)), $data['answers']),
                ]);
                if (isset($data['evidence_images'])) {
                    $latestClaim->evidenceImages()->delete();
                    foreach ($data['evidence_images'] as $image) {
                        $latestClaim->evidenceImages()->create(['path' => $images->store($image)]);
                    }
                }

                return [$latestClaim->fresh(), true];
            }

            if ($latestClaim && $latestClaim->status === 'approved' && in_array($latestClaim->author_decision, ['pending', 'approved'], true)) {
                abort(409, 'You already have an active approved claim for this post.');
            }

            $attemptLimit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
            abort_if(
                $userClaims->count() >= $attemptLimit,
                429,
                "You have used all {$attemptLimit} claim attempts for this item.",
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
                    'title' => 'Someone tried to claim your found item',
                    'body' => 'A claim for '.$lockedPost->item_name.' is waiting for TMC administrator review.',
                    'post_id' => $lockedPost->id,
                    'claim_id' => $claim->id,
                ],
            ]);

            return [$claim->fresh(), false];
        });

        return response()->json([
            'message' => $updated
                ? 'Your claim details were updated.'
                : 'Your claim is waiting for administrator review.',
            'claim_id' => $claim->id,
            'attempt_no' => $claim->attempt_no,
            'attempt_limit' => (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3,
            'updated' => $updated,
            'editable' => $claim->status === 'pending'
                && $claim->created_at->gt(now()->subHour()),
        ], $updated ? 200 : 201);
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

        $isRejected = $claim->status === 'rejected' || $claim->author_decision === 'rejected';
        $attemptLimit = (int) DB::table('settings')->where('key', 'claim_attempts')->value('value') ?: 3;
        $canReapply = $isRejected && $userClaims->count() < $attemptLimit;

        return response()->json(['data' => [
            'id' => $claim->id,
            'answers' => $claim->answers,
            'evidence_images' => $claim->evidenceImages
                ->map(fn ($image): string => \App\Support\PublicMediaUrl::forPath($image->path))
                ->all(),
            'status' => $claim->status,
            'author_decision' => $claim->author_decision,
            'created_at' => $claim->created_at,
            'editable_until' => $claim->created_at->copy()->addHour(),
            'editable' => $claim->status === 'pending'
                && $claim->created_at->gt(now()->subHour()),
            'is_rejected' => $isRejected,
            'attempts_used' => $userClaims->count(),
            'attempt_limit' => $attemptLimit,
            'can_reapply' => $canReapply,
        ]]);
    }

    public function awaitingPostAuthor(Request $request): JsonResponse
    {
        $claims = ClaimRequest::with([
            'post:id,item_name,type,category_id',
            'post.category:id,name',
            'claimant:id,first_name,last_name',
            'evidenceImages',
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
                'evidence_images' => $claim->evidenceImages
                    ->map(fn ($image): string => \App\Support\PublicMediaUrl::forPath($image->path))
                    ->all(),
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
                $conversation = Conversation::firstOrCreate(['claim_id' => $claim->id]);
                Handover::firstOrCreate(['claim_id' => $claim->id]);
                $claim->claimant->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'claim_owner_approved',
                    'data' => [
                        'title' => 'The item poster agreed',
                        'body' => 'The item poster agreed to your approved claim. Open Inbox > Handovers to confirm the exchange.',
                        'claim_id' => $claim->id,
                        'post_id' => $claim->post_id,
                        'conversation_id' => $conversation->id,
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
