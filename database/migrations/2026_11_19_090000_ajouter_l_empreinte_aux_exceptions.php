<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const string TABLE = 'filament_exceptions_table';

    private const string INDEX = 'filament_exceptions_table_empreinte_created_at_index';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'empreinte')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->char('empreinte', 32)->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'occurrences')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedInteger('occurrences')->default(1)->after('empreinte');
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['empreinte', 'created_at'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        foreach (['occurrences', 'empreinte'] as $colonne) {
            if (Schema::hasColumn(self::TABLE, $colonne)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($colonne): void {
                    $table->dropColumn($colonne);
                });
            }
        }
    }
};
