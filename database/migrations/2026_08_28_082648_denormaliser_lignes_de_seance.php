<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le proprietaire et la date de la seance, recopies sur ses lignes.
 *
 * « Quelle est la derniere fois que cet utilisateur a fait cet exercice ? »
 * filtre sur `workout_lines` et ordonne sur `workouts` : aucun index ne peut
 * servir les deux, donc MySQL materialise toute la jointure et la trie avant
 * d'en prendre une ligne. Mesure faite sur un exercice present dans chaque
 * seance : 51, 202 puis 601 lignes lues pour 50, 200 et 600 seances.
 *
 * Avec les deux colonnes ici, l'index sert le filtre ET l'ordre, et le `LIMIT 1`
 * s'arrete a la premiere ligne.
 *
 * Pas de cle etrangere sur `user_id` : la copie n'est pas le lien, `workout_id`
 * l'est deja et porte la cascade. En ajouter une doublerait le cout d'ecriture
 * pour garantir ce que la source garantit deja.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('workout_lines', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('workout_id');
            }

            if (! Schema::hasColumn('workout_lines', 'workout_started_at')) {
                $table->dateTime('workout_started_at')->nullable()->after('user_id');
            }
        });

        DB::statement(
            'update workout_lines
                join workouts on workouts.id = workout_lines.workout_id
              set workout_lines.user_id = workouts.user_id,
                  workout_lines.workout_started_at = workouts.started_at'
        );

        if (Schema::hasIndex('workout_lines', 'workout_lines_user_exercise_date_index')) {
            return;
        }

        Schema::table('workout_lines', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'exercise_id', 'workout_started_at'],
                'workout_lines_user_exercise_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            $table->dropIndex('workout_lines_user_exercise_date_index');
            $table->dropColumn(['user_id', 'workout_started_at']);
        });
    }
};
