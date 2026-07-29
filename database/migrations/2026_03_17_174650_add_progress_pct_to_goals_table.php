<?php

declare(strict_types=1);

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
        Schema::table('goals', function (Blueprint $table) {
            // Using a simpler formula to avoid complex MySQL/SQLite cross-compatibility issues,
            // or we just define it as a standard double column updated by the service.
            // Since the problem suggested "MySQL Virtual Columns", we will use virtualAs.
            // IF is MySQL specific, SQLite uses IIF or CASE WHEN. Let's use a standard column updated on save
            // for maximum stability across test environments (SQLite) and prod (MySQL).
            $table->double('progress_pct')->default(0.0)->after('start_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('progress_pct');
        });
    }
};
