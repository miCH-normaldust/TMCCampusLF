<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('handovers')
            ->where('status', 'awaiting_agreements')
            ->update([
                'status' => 'awaiting_participants',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('handovers')
            ->where('status', 'awaiting_participants')
            ->whereNull('owner_confirmed_at')
            ->whereNull('finder_confirmed_at')
            ->whereNull('scheduled_at')
            ->update([
                'status' => 'awaiting_agreements',
                'updated_at' => now(),
            ]);
    }
};
