<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            if (! Schema::hasIndex('exercises', ['user_id', 'category', 'name'])) {
                $table->index(['user_id', 'category', 'name']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            // Check if index exists before dropping to avoid errors
            try {
                $table->dropIndex(['user_id', 'category', 'name']);
            } catch (\Throwable $e) {
                // Index might not exist or be named differently, safe to ignore in down
            }
        });
    }
};
