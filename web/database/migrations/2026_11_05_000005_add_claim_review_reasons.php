<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('claim_requests', 'author_decision_reason')) {
                $table->text('author_decision_reason')->nullable();
            }
            if (! Schema::hasColumn('claim_requests', 'review_reason')) {
                $table->text('review_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('claim_requests', function (Blueprint $table): void {
            $columns = [];
            if (Schema::hasColumn('claim_requests', 'author_decision_reason')) {
                $columns[] = 'author_decision_reason';
            }
            if (Schema::hasColumn('claim_requests', 'review_reason')) {
                $columns[] = 'review_reason';
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
