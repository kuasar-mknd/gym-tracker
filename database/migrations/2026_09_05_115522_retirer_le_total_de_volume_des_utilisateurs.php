<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'total_volume')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('total_volume');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('total_volume', 15, 2)->default(0)->after('longest_streak');
        });
    }
};
