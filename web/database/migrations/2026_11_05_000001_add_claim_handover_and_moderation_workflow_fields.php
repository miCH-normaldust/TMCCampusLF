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

        if (! Schema::hasTable('claim_evidence_images')) {
            Schema::create('claim_evidence_images', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('claim_id')->constrained('claim_requests')->cascadeOnDelete();
                $table->string('path');
                $table->timestamps();
            });
        }

        Schema::table('handovers', function (Blueprint $table): void {
            if (! Schema::hasColumn('handovers', 'code_hash')) {
                $table->string('code_hash')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'code_encrypted')) {
                $table->text('code_encrypted')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'code_lookup')) {
                $table->char('code_lookup', 64)->nullable();
            }
            if (! Schema::hasColumn('handovers', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'location_id')) {
                $table->foreignId('location_id')->nullable()
                    ->constrained('meetup_locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('handovers', 'status')) {
                $table->string('status', 30)->default('awaiting_admin');
            }
            if (! Schema::hasColumn('handovers', 'admin_approved_at')) {
                $table->timestamp('admin_approved_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'reschedule_attempts')) {
                $table->unsignedTinyInteger('reschedule_attempts')->default(0);
            }
            if (! Schema::hasColumn('handovers', 'reschedule_requested_at')) {
                $table->timestamp('reschedule_requested_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'requested_date')) {
                $table->date('requested_date')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'reschedule_reason')) {
                $table->text('reschedule_reason')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'unsuccessful_at')) {
                $table->timestamp('unsuccessful_at')->nullable();
            }
            if (! Schema::hasColumn('handovers', 'failure_reason')) {
                $table->string('failure_reason', 500)->nullable();
            }
        });
        if (! Schema::hasIndex('handovers', 'handovers_code_lookup_unique')) {
            Schema::table('handovers', fn (Blueprint $table) => $table->unique('code_lookup'));
        }
        if (! Schema::hasIndex('handovers', 'handovers_expires_at_index')) {
            Schema::table('handovers', fn (Blueprint $table) => $table->index('expires_at'));
        }
        DB::table('handovers')
            ->whereNotNull('owner_confirmed_at')
            ->whereNotNull('finder_confirmed_at')
            ->update(['status' => 'completed', 'completed_at' => DB::raw('updated_at')]);

        Schema::table('reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('reports', 'action')) {
                $table->string('action', 30)->nullable();
            }
            if (! Schema::hasColumn('reports', 'admin_reason')) {
                $table->text('admin_reason')->nullable();
            }
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
