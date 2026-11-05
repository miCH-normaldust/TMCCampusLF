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
        'status',
        'admin_approved_at',
        'scheduled_at',
        'reschedule_attempts',
        'reschedule_requested_at',
        'requested_date',
        'reschedule_reason',
        'completed_at',
        'unsuccessful_at',
        'failure_reason',
        'owner_code_hash',
        'owner_code_encrypted',
        'owner_code_lookup',
        'finder_code_hash',
        'finder_code_encrypted',
        'finder_code_lookup',
        'expires_at',
        'location_id',
    ];

    protected function casts(): array
    {
        return [
            'owner_confirmed_at' => 'datetime',
            'finder_confirmed_at' => 'datetime',
            'admin_approved_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'reschedule_requested_at' => 'datetime',
            'requested_date' => 'date',
            'completed_at' => 'datetime',
            'unsuccessful_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(MeetupLocation::class, 'location_id');
    }
}
