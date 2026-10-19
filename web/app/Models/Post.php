<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'item_name',
        'description',
        'category_id',
        'color',
        'location_id',
        'event_date',
        'custody',
        'status',
        'hidden_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'expires_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PostImage::class);
    }

    public function verificationQuestions(): HasMany
    {
        return $this->hasMany(VerificationQuestion::class);
    }

    public function claimRequests(): HasMany
    {
        return $this->hasMany(ClaimRequest::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function sightings(): HasMany
    {
        return $this->hasMany(LostSighting::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PostMatch::class);
    }
}
