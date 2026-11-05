<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['claim_requests', 'lost_sightings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'owner_decision_batch_id')) {
                    $table->uuid('owner_decision_batch_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'owner_decision_batch_winner_id')) {
                    $table->unsignedBigInteger('owner_decision_batch_winner_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['claim_requests', 'lost_sightings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'owner_decision_batch_id')) {
                    $table->dropIndex(['owner_decision_batch_id']);
                    $table->dropColumn('owner_decision_batch_id');
                }
                if (Schema::hasColumn($tableName, 'owner_decision_batch_winner_id')) {
                    $table->dropColumn('owner_decision_batch_winner_id');
                }
            });
        }
    }
};
