<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_picture_path')->nullable();
            $table->string('bio', 500)->nullable();
            $table->string('current_address')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('contact_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_picture_path',
                'bio',
                'current_address',
                'permanent_address',
                'contact_number',
                'contact_email',
            ]);
        });
    }
};
