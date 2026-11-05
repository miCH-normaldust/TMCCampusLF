<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('handovers')
            ->where('status', 'awaiting_admin')
            ->update([
                'status' => 'awaiting_participants',
                'admin_approved_at' => DB::raw('COALESCE(admin_approved_at, updated_at, created_at)'),
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
                'status' => 'awaiting_admin',
                'admin_approved_at' => null,
                'updated_at' => now(),
            ]);
    }
};
