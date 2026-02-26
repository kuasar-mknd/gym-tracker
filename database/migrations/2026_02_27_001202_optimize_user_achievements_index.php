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
        Schema::table('user_achievements', function (Blueprint $table) {
            if (! Schema::hasIndex('user_achievements', ['user_id', 'achieved_at'])) {
                $table->index(['user_id', 'achieved_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_achievements', function (Blueprint $table) {
            try {
                $table->dropIndex(['user_id', 'achieved_at']);
            } catch (\Throwable $e) {
                // Index might not exist
            }
        });
    }
};
