<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le proprietaire, recopie sur les series.
 *
 * `GET /api/v1/sets` filtre par une double jointure — `sets` vers
 * `workout_lines` vers `workouts` — et ordonne sur `sets`. Aucun index ne peut
 * servir les deux : MySQL materialise la jointure entiere et la trie avant
 * d'en prendre quinze lignes. Mesure aux compteurs `Handler_read_*` : 484
 * lectures pour 120 series, 2 802 pour 600, avec `Using temporary; Using
 * filesort` au plan.
 *
 * L'index porte `(user_id, id)` : le tri de la liste est par identifiant
 * decroissant, et InnoDB range de toute facon chaque entree par la clef
 * primaire. Le `LIMIT` s'arrete alors a la quinzieme ligne.
 *
 * Pas de cle etrangere : la copie n'est pas le lien, `workout_line_id` l'est
 * deja et porte la cascade. Meme raisonnement qu'en #1601 et #1604.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sets', 'user_id')) {
            Schema::table('sets', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('workout_line_id');
            });
        }

        DB::statement(
            'update sets
                join workout_lines on workout_lines.id = sets.workout_line_id
              set sets.user_id = workout_lines.user_id'
        );

        if (Schema::hasIndex('sets', 'sets_user_id_id_index')) {
            return;
        }

        Schema::table('sets', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'sets_user_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table): void {
            $table->dropIndex('sets_user_id_id_index');
            $table->dropColumn('user_id');
        });
    }
};
