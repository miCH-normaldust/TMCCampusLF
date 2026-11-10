<?php

namespace App\Http\Resources;

use App\Models\ClaimRequest;
use App\Models\LostSighting;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authenticatedUserId = $request->user()?->getAuthIdentifier();
        $isMine = $authenticatedUserId !== null
            && (string) $authenticatedUserId === (string) $this->user_id;
        $myClaim = $authenticatedUserId !== null && $this->type === 'found'
            ? ($this->relationLoaded('claimRequests')
                ? $this->claimRequests->first()
                : $this->claimRequests()
                    ->where('claimant_id', $authenticatedUserId)
                    ->latest()
                    ->orderByDesc('id')
                    ->first(['status', 'author_decision']))
            : null;
        $hasSubmittedClaim = $myClaim !== null
            && $myClaim->status !== 'rejected'
            && $myClaim->author_decision !== 'rejected';
        $isHandoverParticipant = $isMine;
        if (
            ! $isHandoverParticipant
            && $authenticatedUserId !== null
            && $this->status === 'handover_pending'
        ) {
            $isHandoverParticipant = match ($this->type) {
                'lost' => LostSighting::where('post_id', $this->id)
                    ->where('user_id', $authenticatedUserId)
                    ->where('answer', 'yes')
                    ->where('author_decision', 'approved')
                    ->where('admin_decision', 'approved')
                    ->exists(),
                'found' => ClaimRequest::where('post_id', $this->id)
                    ->where('claimant_id', $authenticatedUserId)
                    ->where('status', 'approved')
                    ->whereIn('author_decision', ['pending', 'approved'])
                    ->exists(),
                default => false,
            };
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'item_name' => $this->item_name,
            'description' => $this->description,
            'category' => $this->custom_category ?: $this->category?->name,
            'category_id' => $this->category_id,
            'category_other' => $this->custom_category,
            'color' => $this->color,
            'location' => $this->custom_location ?: $this->location?->name,
            'location_other' => $this->custom_location,
            'event_date' => $this->event_date?->toDateString(),
            'custody' => $this->custody,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'reactions_count' => $this->reactions_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,
            'my_reaction' => $this->whenLoaded(
                'reactions',
                fn () => $this->reactions->isNotEmpty() ? 'like' : null,
            ),
            'author_id' => $this->user_id,
            'author_first_name' => $this->author?->first_name,
            'author_last_name' => $this->author?->last_name,
            'author_profile_picture_url' => $this->author?->profile_picture_path
                ? PublicMediaUrl::forPath($this->author->profile_picture_path)
                : null,
            'is_mine' => $isMine,
            'has_submitted_claim' => $hasSubmittedClaim,
            'is_handover_participant' => $isHandoverParticipant,
            'retrieval_request_status' => $isMine
                ? $this->whenLoaded('latestRetrievalRequest', fn () => $this->latestRetrievalRequest?->status)
                : null,
            'images' => $this->images->map(fn ($image): array => [
                'url' => PublicMediaUrl::forPath($image->path),
                'alt_text' => $image->alt_text,
            ]),
            'verification_questions' => $this->when(
                $this->type === 'found',
                fn () => $this->verificationQuestions->map(fn ($question): array => [
                    'id' => $question->id,
                    'question' => $question->question,
                ]),
            ),
        ];
    }
}
