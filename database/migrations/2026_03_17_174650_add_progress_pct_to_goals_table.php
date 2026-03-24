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
        if (Schema::hasTable("goals")) {
            Schema::table("goals", function (Blueprint $table) {
                if (!Schema::hasColumn("goals", "progress_pct")) {
                    $table->double("progress_pct")->default(0.0)->after("start_value");
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable("goals")) {
            Schema::table("goals", function (Blueprint $table) {
                if (Schema::hasColumn("goals", "progress_pct")) {
                    $table->dropColumn("progress_pct");
                }
            });
        }
    }
};
