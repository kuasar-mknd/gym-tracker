<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            if (Schema::hasIndex('workout_lines', 'workout_lines_workout_exercise_index')) {
                return;
            }

            // Rend couvrant le COUNT DISTINCT de la carte « Exercices » : la
            // table portait `(workout_id)` et `(exercise_id, workout_id)`, dans
            // cet ordre, donc chaque ligne trouvee par la jointure imposait un
            // acces a la ligne complete.
            $table->index(['workout_id', 'exercise_id'], 'workout_lines_workout_exercise_index');
        });

        Schema::table('fasts', function (Blueprint $table): void {
            if (Schema::hasIndex('fasts', 'fasts_user_id_status_index')) {
                return;
            }

            // `status` n'etait dans aucun index. Le cas ou l'on DEMARRE un jeune
            // est celui ou aucun n'est actif : l'`exists()` ne sortait pas tot et
            // parcourait tout l'historique pour rendre faux.
            $table->index(['user_id', 'status', 'start_time'], 'fasts_user_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('workout_lines', function (Blueprint $table): void {
            $table->dropIndex('workout_lines_workout_exercise_index');
        });

        Schema::table('fasts', function (Blueprint $table): void {
            $table->dropIndex('fasts_user_id_status_index');
        });
    }
};
