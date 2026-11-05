<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['proof_attempts' => '3', 'handover_reschedule_attempts' => '2'] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Schema::create('claim_evidence_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->constrained('claim_requests')->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        Schema::table('handovers', function (Blueprint $table): void {
            $table->string('code_hash')->nullable();
            $table->text('code_encrypted')->nullable();
            $table->char('code_lookup', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('location_id')->nullable()
                ->constrained('meetup_locations')->nullOnDelete();
            $table->string('status', 30)->default('awaiting_admin');
            $table->timestamp('admin_approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedTinyInteger('reschedule_attempts')->default(0);
            $table->timestamp('reschedule_requested_at')->nullable();
            $table->date('requested_date')->nullable();
            $table->text('reschedule_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('unsuccessful_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
        });
        DB::table('handovers')
            ->whereNotNull('owner_confirmed_at')
            ->whereNotNull('finder_confirmed_at')
            ->update(['status' => 'completed', 'completed_at' => DB::raw('updated_at')]);

        Schema::table('reports', function (Blueprint $table): void {
            $table->string('action', 30)->nullable();
            $table->text('admin_reason')->nullable();
            $table->dropForeign(['post_id']);
            $table->foreign('post_id')->references('id')->on('posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropForeign(['post_id']);
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->dropColumn(['action', 'admin_reason']);
        });

        Schema::table('handovers', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropUnique(['code_lookup']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'code_hash', 'code_encrypted', 'code_lookup', 'expires_at', 'location_id',
                'status', 'admin_approved_at', 'scheduled_at', 'reschedule_attempts',
                'reschedule_requested_at', 'requested_date', 'completed_at',
                'reschedule_reason', 'unsuccessful_at', 'failure_reason',
            ]);
        });

        Schema::dropIfExists('claim_evidence_images');
    }
};
