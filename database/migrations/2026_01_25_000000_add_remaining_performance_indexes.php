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
        Schema::table('plates', function (Blueprint $table) {
            if (! Schema::hasIndex('plates', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('user_achievements', function (Blueprint $table) {
            if (! Schema::hasIndex('user_achievements', ['achievement_id'])) {
                $table->index('achievement_id');
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            // Optimizes FetchDashboardDataAction::execute for recentPRs
            // where('user_id', ...)->latest('achieved_at')
            if (! Schema::hasIndex('personal_records', ['user_id', 'achieved_at'])) {
                $table->index(['user_id', 'achieved_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plates', function (Blueprint $table) {
            if (Schema::hasIndex('plates', ['user_id'])) {
                $table->dropIndex(['user_id']);
            }
        });

        Schema::table('user_achievements', function (Blueprint $table) {
            if (Schema::hasIndex('user_achievements', ['achievement_id'])) {
                $table->dropIndex(['achievement_id']);
            }
        });

        Schema::table('personal_records', function (Blueprint $table) {
            if (Schema::hasIndex('personal_records', ['user_id', 'achieved_at'])) {
                $table->dropIndex(['user_id', 'achieved_at']);
            }
        });
    }
};
