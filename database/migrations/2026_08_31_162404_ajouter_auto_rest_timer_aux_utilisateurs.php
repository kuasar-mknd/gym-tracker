<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'auto_rest_timer')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('auto_rest_timer')->default(true)->after('default_rest_time');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'auto_rest_timer')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auto_rest_timer');
        });
    }
};
