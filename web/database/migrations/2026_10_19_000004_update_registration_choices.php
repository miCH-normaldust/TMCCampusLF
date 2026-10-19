<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type', 100)->nullable()->change();
            $table->string('user_type_other', 100)->nullable()->after('user_type');
        });
        Schema::table('tmc_masterlist', function (Blueprint $table): void {
            $table->string('user_type_other', 100)->nullable()->after('user_type');
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'tmc_email_domain'],
            ['value' => 'gmail.com', 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('users')->whereNotIn('user_type', ['student', 'staff', 'instructor'])
            ->update(['user_type' => 'student']);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('user_type_other');
            $table->enum('user_type', ['student', 'staff', 'instructor'])->nullable()->change();
        });
        Schema::table('tmc_masterlist', function (Blueprint $table): void {
            $table->dropColumn('user_type_other');
        });
    }
};
