<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les jours de la semaine ou l'utilisateur veut son rappel d'entrainement.
 *
 * Tableau de numeros ISO (1 = lundi, 7 = dimanche). Nul vaut « tous les
 * jours » : les preferences existantes gardent un rappel quotidien.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_preferences', 'days')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->json('days')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notification_preferences', 'days')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('days');
        });
    }
};
