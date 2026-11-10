<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModerationRestriction extends Model
{
    protected $fillable = [
        'user_id',
        'created_by',
        'target_user_id',
        'scope',
        'details',
        'reason',
        'lifted_at',
        'lifted_by',
        'lift_reason',
    ];

    protected function casts(): array
    {
        return ['lifted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
