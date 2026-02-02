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
        // Redundant migration. The index on water_logs is already handled by 2026_01_27_000000.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty to avoid FK constraint errors in CI (MySQL 8+)
        // The index is redundant with other migrations and dropping it causes Error 1553.
    }
};
