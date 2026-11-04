<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['claim_id']);
            $table->dropUnique(['claim_id']);
            $table->unsignedBigInteger('claim_id')->nullable()->change();
            $table->foreign('claim_id')->references('id')->on('claim_requests')->cascadeOnDelete();
            $table->unique('claim_id');
            $table->foreignId('sighting_id')->nullable()->unique()
                ->constrained('lost_sightings')->cascadeOnDelete();
            $table->foreignId('support_user_id')->nullable()
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('support_sighting_id')->nullable()
                ->constrained('lost_sightings')->nullOnDelete();
            $table->unique(['support_user_id', 'support_sighting_id']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->index(['post_id', 'parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        DB::table('conversations')->whereNull('claim_id')->delete();

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex(['post_id', 'parent_id', 'created_at']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['sighting_id']);
            $table->dropUnique(['sighting_id']);
            $table->dropForeign(['support_user_id']);
            $table->dropForeign(['support_sighting_id']);
            $table->dropUnique(['support_user_id', 'support_sighting_id']);
            $table->dropColumn(['sighting_id', 'support_user_id', 'support_sighting_id']);
            $table->dropForeign(['claim_id']);
            $table->dropUnique(['claim_id']);
            $table->unsignedBigInteger('claim_id')->nullable(false)->change();
            $table->foreign('claim_id')->references('id')->on('claim_requests')->cascadeOnDelete();
            $table->unique('claim_id');
        });
    }
};
