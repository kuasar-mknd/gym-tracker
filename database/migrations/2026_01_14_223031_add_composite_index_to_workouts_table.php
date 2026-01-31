<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            // Add composite index for filtering by user and date sorting/filtering
            if (! Schema::hasIndex('workouts', ['user_id', 'started_at'])) {
                $table->index(['user_id', 'started_at']);
            }

            // Remove the single column index as it is now redundant
            // (The composite index can handle queries that used the single index)
            // But we must check if it exists or if it overlaps with FK
            $indexes = collect(Schema::getIndexes('workouts'));
            if ($indexes->contains('name', 'workouts_user_id_index')) {
                $table->dropIndex('workouts_user_id_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workouts', function (Blueprint $table) {
            if (! Schema::hasIndex('workouts', ['user_id'])) {
                $table->index('user_id');
            }

            try {
                DB::statement('DROP INDEX workouts_user_id_started_at_index ON workouts');
            } catch (\Throwable $e) {
            }
        });
    }
};
