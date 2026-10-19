<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('tmc_id_no', 50)->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['user', 'admin'])->default('user')->index();
            $table->enum('user_type', ['student', 'staff', 'instructor'])->nullable();
            $table->string('department', 150)->nullable();
            $table->enum('status', ['active', 'pending', 'suspended', 'deactivated'])->default('pending')->index();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tmc_masterlist', function (Blueprint $table): void {
            $table->id();
            $table->string('tmc_id_no', 50)->unique();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('user_type', 30);
            $table->string('department', 150);
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->string('purpose', 30)->default('registration');
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        foreach (['categories', 'locations'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100)->unique();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['lost', 'found'])->index();
            $table->string('item_name', 150);
            $table->text('description');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('color', 50);
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->date('event_date')->index();
            $table->enum('custody', ['with_me', 'office'])->nullable();
            $table->enum('status', ['active', 'claim_pending', 'approved_for_meetup', 'retrieved', 'hidden', 'archived'])->default('active')->index();
            $table->text('hidden_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            $table->index(['category_id', 'status', 'created_at']);
            $table->index(['type', 'status', 'event_date']);
        });

        Schema::create('post_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matched_post_id')->constrained('posts')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->timestamps();
            $table->unique(['post_id', 'matched_post_id']);
        });

        Schema::create('post_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt_text', 200);
        });

        Schema::create('verification_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('question', 250);
            $table->text('answer');
            $table->timestamps();
        });

        Schema::create('claim_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claimant_id')->constrained('users')->cascadeOnDelete();
            $table->longText('answers');
            $table->unsignedTinyInteger('attempt_no');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'claimant_id']);
        });

        Schema::create('lost_sightings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('answer', ['yes', 'no']);
            $table->text('evidence_text')->nullable();
            $table->string('evidence_image')->nullable();
            $table->enum('author_decision', ['pending', 'approved', 'rejected'])->default('pending');
            $table->enum('admin_decision', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['like', 'haha', 'angry', 'sad']);
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'dismissed', 'actioned'])->default('pending')->index();
            $table->timestamps();
            $table->unique(['post_id', 'reporter_id']);
        });

        Schema::create('meetup_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('operating_hours', 200)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('claim_requests', function (Blueprint $table): void {
            $table->foreign('location_id')->references('id')->on('meetup_locations')->nullOnDelete();
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->unique()->constrained('claim_requests')->cascadeOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->unique()->constrained('claim_requests')->cascadeOnDelete();
            $table->string('code_hash');
            $table->text('code_encrypted');
            $table->char('code_lookup', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('owner_confirmed_at')->nullable();
            $table->timestamp('finder_confirmed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('meetup_locations')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fcm_token', 500)->unique();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->morphs('notifiable');
            $table->string('type');
            $table->mediumText('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 100)->index();
            $table->ipAddress('ip_address')->nullable();
            $table->string('device', 255)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 100)->index();
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'failed_jobs', 'job_batches', 'jobs', 'settings', 'audit_logs', 'activity_logs',
            'notifications', 'device_tokens', 'handovers', 'messages', 'conversations',
            'reports', 'reactions', 'comments', 'lost_sightings', 'post_matches',
            'claim_requests', 'meetup_locations', 'verification_questions', 'post_images',
            'posts', 'locations', 'categories', 'otp_codes', 'tmc_masterlist', 'users',
            'password_reset_tokens',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
