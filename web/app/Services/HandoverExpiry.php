<?php

namespace App\Services;

use App\Models\Handover;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HandoverExpiry
{
    public function expirePastDue(): int
    {
        $expiredCount = 0;

        Handover::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $handoverId) use (&$expiredCount): void {
                $expired = DB::transaction(function () use ($handoverId): ?Handover {
                    $handover = Handover::whereKey($handoverId)
                        ->lockForUpdate()
                        ->first();

                    if (! $handover
                        || $handover->status !== 'scheduled'
                        || ! $handover->scheduled_at
                        || $handover->scheduled_at->isFuture()) {
                        return null;
                    }

                    $handover->load(['claimRequest.post', 'sighting.post']);
                    $handover->update([
                        'status' => 'unsuccessful',
                        'unsuccessful_at' => now(),
                        'failure_reason' => 'The scheduled handover time passed without completion.',
                    ]);
                    ($handover->claimRequest?->post ?? $handover->sighting?->post)
                        ?->update(['status' => 'active']);

                    return $handover->fresh();
                });

                if (! $expired) {
                    return;
                }

                $claim = $expired->claimRequest;
                $sighting = $expired->sighting;
                $participantIds = array_filter([
                    $claim?->claimant_id ?? $sighting?->post?->user_id,
                    $claim?->post?->user_id ?? $sighting?->user_id,
                ]);
                foreach (User::whereIn('id', $participantIds)->get() as $participant) {
                    $participant->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'handover_unsuccessful',
                        'data' => [
                            'title' => 'Handover expired',
                            'body' => 'The scheduled handover time passed. The transaction ended and the post is active again.',
                            'handover_id' => $expired->id,
                        ],
                    ]);
                }

                $expiredCount++;
            });

        return $expiredCount;
    }
}
