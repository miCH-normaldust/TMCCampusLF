<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'claim_id',
        'sighting_id',
        'support_user_id',
        'support_sighting_id',
        'user_one_id',
        'user_two_id',
        'requested_by',
        'status',
        'closed_at',
    ];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function claimRequest(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_id');
    }

    public function sighting(): BelongsTo
    {
        return $this->belongsTo(LostSighting::class, 'sighting_id');
    }

    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_user_id');
    }

    public function supportSighting(): BelongsTo
    {
        return $this->belongsTo(LostSighting::class, 'support_sighting_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function handover(): HasOne
    {
        return $this->hasOne(Handover::class, 'claim_id', 'claim_id');
    }

    public function sightingHandover(): HasOne
    {
        return $this->hasOne(Handover::class, 'sighting_id', 'sighting_id');
    }

}
