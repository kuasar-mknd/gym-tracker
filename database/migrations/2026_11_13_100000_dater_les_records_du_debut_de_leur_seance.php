<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Date chaque record du début de la séance dont la série le tient, comme le
 * font désormais la montée et la reconstruction (`PersonalRecordService`).
 *
 * Gardent leur date : un record sans série (`set_id` nul), un record sans compte
 * (réécrire `achieved_at` revérifie la clé du compte sous 8.4, 1452) et une
 * séance dont le début sort de la plage d'un `TIMESTAMP` (le mode strict
 * arrêterait l'instruction, donc le démarrage). Rejouable : seules les lignes
 * qui diffèrent sont écrites.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_records') || ! Schema::hasTable('workouts')) {
            return;
        }

        $ecrits = DB::affectingStatement(
            'update personal_records
               join sets on sets.id = personal_records.set_id
               join workout_lines on workout_lines.id = sets.workout_line_id
               join workouts on workouts.id = workout_lines.workout_id
               join users on users.id = personal_records.user_id
                set personal_records.achieved_at = workouts.started_at
              where workouts.started_at between ? and ?
                and personal_records.achieved_at <> workouts.started_at',
            ['1970-01-02 00:00:00', '2038-01-18 00:00:00'],
        );

        if ($ecrits > 0) {
            Log::info('Records datés du début de leur séance', ['records' => $ecrits]);
        }
    }

    /**
     * Volontairement vide : l'ancienne date était l'heure d'un travail de file
     * ou d'une reconstruction, que rien n'a gardé.
     */
    public function down(): void
    {
    }
};
