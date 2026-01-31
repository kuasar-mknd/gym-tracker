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
        Schema::table('personal_records', function (Blueprint $table) {
            // Index for efficient cascading deletes and lookups by exercise alone
            if (! Schema::hasIndex('personal_records', ['exercise_id'])) {
                $table->index('exercise_id');
            }
        });

        Schema::table('user_achievements', function (Blueprint $table) {
            // Index for efficient cascading deletes and reverse relationship lookups (Achievement -> Users)
            if (! Schema::hasIndex('user_achievements', ['achievement_id'])) {
                $table->index('achievement_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::statement('DROP INDEX personal_records_exercise_id_index ON personal_records');
        } catch (\Throwable $e) {
        }

        try {
            DB::statement('DROP INDEX user_achievements_achievement_id_index ON user_achievements');
        } catch (\Throwable $e) {
        }
    }
};
