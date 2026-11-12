<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les jobs échoués se lisent et se purgent par leur date (#2032).
 *
 * `queue:prune-failed` supprime `where failed_at < ?`, et le diagnostic compte
 * `where failed_at >= ?` : sans index sur `failed_at`, les deux parcouraient
 * toute la table, charges utiles comprises.
 */
return new class() extends Migration
{
    private const string INDEX = 'failed_jobs_failed_at_index';

    public function up(): void
    {
        if (! Schema::hasIndex('failed_jobs', self::INDEX)) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->index('failed_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('failed_jobs', self::INDEX)) {
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
