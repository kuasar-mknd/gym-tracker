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
        Schema::table('daily_journals', function (Blueprint $table) {
             if (! Schema::hasIndex('daily_journals', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
             if (! Schema::hasIndex('notification_preferences', ['user_id'])) {
                $table->index('user_id');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            // Check if notifiable_id and notifiable_type index exists (polymorphic)
            // Laravel usually creates this automatically if morphs() is used, but manual creation might be needed if custom.
            // But let's check for direct user relationship if any.
            // Notifications table usually has notifiable_id/type.
        });

        Schema::table('user_achievements', function (Blueprint $table) {
             if (! Schema::hasIndex('user_achievements', ['user_id'])) {
                $table->index('user_id');
            }
             if (! Schema::hasIndex('user_achievements', ['achievement_id'])) {
                // already added in 2026_01_18_000000_optimize_database_indexes.php
             }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('daily_journals')) {
            Schema::table('daily_journals', function (Blueprint $table) {
                if (Schema::hasIndex('daily_journals', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

         if (Schema::hasTable('notification_preferences')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                if (Schema::hasIndex('notification_preferences', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }

         if (Schema::hasTable('user_achievements')) {
            Schema::table('user_achievements', function (Blueprint $table) {
                if (Schema::hasIndex('user_achievements', ['user_id'])) {
                    $table->dropIndex(['user_id']);
                }
            });
        }
    }
};
