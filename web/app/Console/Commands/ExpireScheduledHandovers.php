<?php

namespace App\Console\Commands;

use App\Services\HandoverExpiry;
use Illuminate\Console\Command;

class ExpireScheduledHandovers extends Command
{
    protected $signature = 'campuslf:expire-handovers';

    protected $description = 'End scheduled handovers whose scheduled time has passed';

    public function handle(HandoverExpiry $expiry): int
    {
        $expired = $expiry->expirePastDue();
        $this->info("Expired {$expired} scheduled handover(s).");

        return self::SUCCESS;
    }
}
