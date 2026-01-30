<?php

declare(strict_types=1);

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
        Schema::table('water_logs', function (Blueprint $table) {
            // Index for efficient history querying
            $table->index(['user_id', 'consumed_at']);
        });

        Schema::table('supplement_logs', function (Blueprint $table) {
            // Index for efficient history querying across all supplements
            $table->index(['user_id', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('water_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            //
        }

        try {
            Schema::table('supplement_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'consumed_at']);
            });
        } catch (\Throwable $e) {
            //
        }
    }
};
