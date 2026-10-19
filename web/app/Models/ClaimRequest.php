<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClaimRequest extends Model
{
    protected $fillable = ['post_id', 'claimant_id', 'answers', 'attempt_no', 'status', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'answers' => 'encrypted:array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'claim_id');
    }
}
