<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimEvidenceImage extends Model
{
    protected $fillable = ['path'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_id');
    }
}
