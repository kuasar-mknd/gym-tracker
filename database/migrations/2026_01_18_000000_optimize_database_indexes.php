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
        if (Schema::hasTable('personal_records')) {
            Schema::table('personal_records', function (Blueprint $table) {
                if (Schema::hasIndex('personal_records', ['exercise_id'])) {
                    // Be careful not to drop the composite index if it starts with exercise_id (it doesn't, it starts with user_id)
                    // But standard dropIndex drops by name or column list.
                    $table->dropIndex(['exercise_id']);
                }
            });
        }

        if (Schema::hasTable('user_achievements')) {
            Schema::table('user_achievements', function (Blueprint $table) {
                if (Schema::hasIndex('user_achievements', ['achievement_id'])) {
                    $table->dropIndex(['achievement_id']);
                }
            });
        }
    }
};
