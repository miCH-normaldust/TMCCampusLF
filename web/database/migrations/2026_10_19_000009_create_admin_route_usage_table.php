<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_route_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('route_name', 150);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('interactions')->default(0);
            $table->timestamps();
            $table->unique(['admin_id', 'route_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_route_usage');
    }
};
