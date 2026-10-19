<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LostSighting extends Model
{
    protected $fillable = [
        'post_id',
        'user_id',
        'answer',
        'evidence_text',
        'evidence_image',
        'author_decision',
        'admin_decision',
        'admin_reason',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handover(): HasOne
    {
        return $this->hasOne(Handover::class, 'sighting_id');
    }
}
