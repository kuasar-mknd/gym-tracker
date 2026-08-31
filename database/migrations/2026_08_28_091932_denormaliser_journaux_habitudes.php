<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le proprietaire de l'habitude, recopie sur ses journaux.
 *
 * Rien ne reliait un journal a son utilisateur sans passer par `habits` : le
 * filtre etait donc sur une table et l'ordre sur l'autre, et aucun index ne
 * pouvait servir les deux. Mesure aux compteurs `Handler_read_*` : 622 puis
 * 3 022 lectures pour 300 puis 1 500 journaux, sur un point d'entree pagine
 * qui n'en rend que quinze.
 *
 * `habit_logs_date_index` disparait : une date sans proprietaire ne sert
 * aucune requete de l'application, et `(user_id, date)` la couvre.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('habit_logs', 'user_id')) {
            Schema::table('habit_logs', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('habit_id');
            });
        }

        DB::statement(
            'update habit_logs
                join habits on habits.id = habit_logs.habit_id
              set habit_logs.user_id = habits.user_id'
        );

        if (! Schema::hasIndex('habit_logs', 'habit_logs_user_date_index')) {
            Schema::table('habit_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'date'], 'habit_logs_user_date_index');
            });
        }

        if (Schema::hasIndex('habit_logs', 'habit_logs_date_index')) {
            Schema::table('habit_logs', function (Blueprint $table): void {
                $table->dropIndex('habit_logs_date_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('habit_logs', function (Blueprint $table): void {
            $table->index(['date'], 'habit_logs_date_index');
        });

        Schema::table('habit_logs', function (Blueprint $table): void {
            $table->dropIndex('habit_logs_user_date_index');
            $table->dropColumn('user_id');
        });
    }
};
