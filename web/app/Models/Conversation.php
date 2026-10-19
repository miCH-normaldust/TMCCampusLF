<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = ['claim_id', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function claimRequest(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function handover(): HasOne
    {
        return $this->hasOne(Handover::class, 'claim_id', 'claim_id');
    }
}
