<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'item_name' => $this->item_name,
            'description' => $this->description,
            'category' => $this->category?->name,
            'color' => $this->color,
            'location' => $this->location?->name,
            'event_date' => $this->event_date?->toDateString(),
            'custody' => $this->custody,
            'status' => $this->status,
            'author_first_name' => $this->author?->first_name,
            'is_mine' => $request->user()?->id === $this->user_id,
            'images' => $this->images->map(fn ($image): array => [
                'url' => asset('storage/'.$image->path),
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
