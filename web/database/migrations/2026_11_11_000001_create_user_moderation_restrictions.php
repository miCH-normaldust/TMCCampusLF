<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_moderation_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 40)->index();
            $table->text('details')->nullable();
            $table->text('reason');
            $table->timestamp('lifted_at')->nullable()->index();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'scope', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_moderation_restrictions');
    }
};
