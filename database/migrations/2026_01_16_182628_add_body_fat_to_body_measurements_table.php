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
        Schema::table('body_measurements', function (Blueprint $table) {
            $table->decimal('body_fat', 5, 2)->nullable()->after('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('body_measurements', function (Blueprint $table) {
            $table->dropColumn('body_fat');
        });
    }
};
