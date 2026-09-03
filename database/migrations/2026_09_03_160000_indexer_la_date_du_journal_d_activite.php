<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La purge planifiée filtre sur created_at ; sans index elle balaye la table
 * entière, qui n'a jamais été purgée.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('activity_log', 'activity_log_created_at_index')) {
            return;
        }

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index('created_at', 'activity_log_created_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('activity_log', 'activity_log_created_at_index')) {
            return;
        }

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_created_at_index');
        });
    }
};
