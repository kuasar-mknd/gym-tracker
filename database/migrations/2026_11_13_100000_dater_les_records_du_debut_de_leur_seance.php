<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Date chaque record du début de la séance dont la série le tient.
 *
 * `personal_records.achieved_at` avait deux écrivains et deux sens : la
 * synchronisation écrivait l'heure du travail de file (celle du rejeu pour une
 * série saisie hors ligne), la reconstruction la date de création de la série
 * (ou l'heure de la reconstruction), si bien qu'un même record changeait de
 * date à la première reconstruction, et qu'aucune des deux n'était le moment de
 * la séance. Les deux écrivent désormais le début de la séance de la série
 * (`PersonalRecordService`), le jour que les statistiques et les séries de
 * jours lisent ; cette migration y ramène les lignes existantes, en une seule
 * instruction.
 *
 * Ne sont pas touchés : un record dont la série a disparu (`set_id` nul, la
 * clé étrangère l'a détaché), un record dont le compte n'existe plus (réécrire
 * `achieved_at` réécrit l'index `(user_id, achieved_at)`, que MySQL 8.4 revérifie
 * contre la clé du compte, 1452), et une séance dont le début sort de la plage
 * d'un `TIMESTAMP` (un jour de marge à chaque bout, comme
 * `DateQueSaColonneTient`) : le mode strict arrêterait l'instruction, donc le
 * démarrage. Ils gardent leur date.
 *
 * Rejouable : seules les lignes dont la date diffère du début de leur séance
 * sont écrites, un second passage n'en trouve plus.
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
