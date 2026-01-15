<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            // Add composite index for filtering by user and date sorting/filtering
            $table->index(['user_id', 'started_at']);

            // Remove the single column index as it is now redundant
            // (The composite index can handle queries that used the single index)
            $table->dropIndex(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropIndex(['user_id', 'started_at']);
        });
    }
};
