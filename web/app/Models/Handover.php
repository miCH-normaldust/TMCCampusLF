<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Handover extends Model
{
    protected $fillable = [
        'claim_id',
        'sighting_id',
        'code_hash',
        'code_encrypted',
        'code_lookup',
        'expires_at',
        'owner_confirmed_at',
        'finder_confirmed_at',
        'verified_by',
        'location_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'code_encrypted' => 'encrypted',
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

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MeetupLocation::class, 'location_id');
    }
}
