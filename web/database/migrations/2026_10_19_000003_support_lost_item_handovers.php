<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', function (Blueprint $table): void {
            $table->dropForeign(['claim_id']);
            $table->dropUnique(['claim_id']);
            $table->unsignedBigInteger('claim_id')->nullable()->change();
            $table->foreign('claim_id')->references('id')->on('claim_requests')->cascadeOnDelete();
            $table->unique('claim_id');
            $table->foreignId('sighting_id')->nullable()->unique()
                ->constrained('lost_sightings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('handovers', function (Blueprint $table): void {
            $table->dropForeign(['sighting_id']);
            $table->dropUnique(['sighting_id']);
            $table->dropForeign(['claim_id']);
            $table->dropUnique(['claim_id']);
            $table->dropColumn('sighting_id');
            $table->unsignedBigInteger('claim_id')->nullable(false)->change();
            $table->foreign('claim_id')->references('id')->on('claim_requests')->cascadeOnDelete();
            $table->unique('claim_id');
        });
    }
};
