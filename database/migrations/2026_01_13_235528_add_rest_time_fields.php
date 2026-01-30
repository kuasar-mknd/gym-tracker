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
        Schema::table('exercises', function (Blueprint $table) {
            $table->integer('default_rest_time')->nullable()->after('type');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('default_rest_time')->default(90)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('default_rest_time');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_rest_time');
        });
    }
};
