<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMatch extends Model
{
    protected $fillable = ['post_id', 'matched_post_id', 'score'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function matchedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'matched_post_id');
    }
}
