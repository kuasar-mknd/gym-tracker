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
        Schema::table('water_logs', function (Blueprint $table) {
            $table->index(['user_id', 'consumed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('water_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'consumed_at']);
        });

        Schema::enableForeignKeyConstraints();
    }
};
