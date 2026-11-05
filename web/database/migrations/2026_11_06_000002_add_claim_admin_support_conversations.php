<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('support_claim_id')->nullable()
                ->constrained('claim_requests')->cascadeOnDelete();
            $table->unique(['support_user_id', 'support_claim_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['support_user_id', 'support_claim_id']);
            $table->dropConstrainedForeignId('support_claim_id');
        });
    }
};
