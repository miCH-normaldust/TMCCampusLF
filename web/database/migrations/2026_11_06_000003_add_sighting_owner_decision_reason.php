<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lost_sightings', 'author_decision_reason')) {
            Schema::table('lost_sightings', function (Blueprint $table): void {
                $table->text('author_decision_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lost_sightings', 'author_decision_reason')) {
            Schema::table('lost_sightings', function (Blueprint $table): void {
                $table->dropColumn('author_decision_reason');
            });
        }
    }
};
