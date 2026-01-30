<?php

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
        Schema::table('water_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('water_logs', function (Blueprint $table) {
            // Drop FK to allow dropping the index safely
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'consumed_at']);
            // Re-add FK
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
