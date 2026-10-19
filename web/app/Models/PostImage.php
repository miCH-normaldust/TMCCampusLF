<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostImage extends Model
{
    public $timestamps = false;

    protected $fillable = ['post_id', 'path', 'alt_text'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
