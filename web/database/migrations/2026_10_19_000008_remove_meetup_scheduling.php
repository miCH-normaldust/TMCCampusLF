<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_requests', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
            $table->enum('author_decision', ['pending', 'approved', 'rejected'])->default('pending')->after('status');
        });

        Schema::table('handovers', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['location_id']);
            $table->dropUnique(['code_lookup']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'code_hash',
                'code_encrypted',
                'code_lookup',
                'expires_at',
                'verified_by',
                'location_id',
            ]);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->string('status')->default('active')->change();
        });

        DB::table('posts')->where('status', 'approved_for_meetup')->update([
            'status' => 'handover_pending',
        ]);
    }

    public function down(): void
    {
        Schema::table('claim_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('location_id')->nullable();
            $table->dropColumn('author_decision');
            $table->foreign('location_id')->references('id')->on('meetup_locations')->nullOnDelete();
        });

        Schema::table('handovers', function (Blueprint $table): void {
            $table->string('code_hash')->nullable();
            $table->text('code_encrypted')->nullable();
            $table->char('code_lookup', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('meetup_locations')->nullOnDelete();
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->enum('status', ['active', 'claim_pending', 'approved_for_meetup', 'retrieved', 'hidden', 'archived'])
                ->default('active')
                ->change();
        });

        DB::table('posts')->where('status', 'handover_pending')->update([
            'status' => 'approved_for_meetup',
        ]);
    }
};
