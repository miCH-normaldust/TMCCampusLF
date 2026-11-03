<?php

namespace App\Http\Resources;

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

        return [
            'id' => $this->id,
            'type' => $this->type,
            'item_name' => $this->item_name,
            'description' => $this->description,
            'category' => $this->category?->name,
            'category_id' => $this->category_id,
            'color' => $this->color,
            'location' => $this->location?->name,
            'event_date' => $this->event_date?->toDateString(),
            'custody' => $this->custody,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'reactions_count' => $this->reactions_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,
            'my_reaction' => $this->whenLoaded(
                'reactions',
                fn () => $this->reactions->first()?->type,
            ),
            'author_id' => $this->user_id,
            'author_first_name' => $this->author?->first_name,
            'author_last_name' => $this->author?->last_name,
            'author_profile_picture_url' => $this->author?->profile_picture_path
                ? PublicMediaUrl::forPath($this->author->profile_picture_path)
                : null,
            'is_mine' => $isMine,
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
