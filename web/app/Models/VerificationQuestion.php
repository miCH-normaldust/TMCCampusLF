<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationQuestion extends Model
{
    protected $fillable = ['post_id', 'question', 'answer'];

    protected function casts(): array
    {
        return ['answer' => 'encrypted'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
