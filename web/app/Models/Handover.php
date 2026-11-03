<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Handover extends Model
{
    protected $fillable = [
        'claim_id',
        'sighting_id',
        'owner_confirmed_at',
        'finder_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_confirmed_at' => 'datetime',
            'finder_confirmed_at' => 'datetime',
        ];
    }

    public function claimRequest(): BelongsTo
    {
        return $this->belongsTo(ClaimRequest::class, 'claim_id');
    }

    public function sighting(): BelongsTo
    {
        return $this->belongsTo(LostSighting::class, 'sighting_id');
    }

}
