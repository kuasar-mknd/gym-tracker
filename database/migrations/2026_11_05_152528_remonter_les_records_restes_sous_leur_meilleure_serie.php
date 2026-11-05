<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\User;
use App\Services\PersonalRecordService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remonte, sans rien annoncer, les records restés sous la meilleure série
 * éligible de leur exercice, et crée le record suivi qui manque à un exercice
 * qui a une telle série (#2185).
 *
 * Depuis #2185, la reconstruction lancée par une écriture de l'application
 * (une série corrigée, décochée ou supprimée, une ligne retirée, une séance
 * supprimée) annonce le record qu'elle donne au-dessus de sa valeur à une
 * autre série que celle qui le tenait, ou qu'elle crée
 * (`PersonalRecordService::reconstruire()`). Elle raisonne sur un invariant :
 * une série que sa synchronisation a écrite laisse chaque record au moins à
 * sa valeur. Des records d'avant les correctifs ne le tiennent pas : deux
 * synchronisations croisées avant #1984, la plus lente écrivant 100 kg
 * par-dessus 105 ; la ligne gardée par `un_seul_record_par_type`, dont la
 * valeur pouvait être fausse ; un type hérité (#1811) à la place d'un type
 * suivi. Le contrôle de nuit signale le premier écart (« records égaux à la
 * meilleure série éligible ») sans le réparer, à dessein. Sur un tel
 * exercice, supprimer une ancienne séance annonçait de nouveau « … avec
 * 105 kg », que la synchronisation de la série avait déjà annoncé.
 *
 * La migration établit l'invariant une fois. Pour chaque couple (compte,
 * exercice) des lignes de séance, elle compare les records suivis à ce que
 * donne la meilleure série éligible (`recordsAttendus()`, le classement et la
 * mesure de la reconstruction) ; un record qui manque, ou qui reste dessous,
 * fait reconstruire l'exercice par `reparer()`, sous son verrou, sans rien
 * annoncer, comme `app:verify-data-coherence --repair`. Sous ce verrou, les
 * écritures de l'application tiennent ensuite l'invariant (#1984).
 *
 * Le reste n'est pas touché. Un record resté au-dessus de sa meilleure série
 * (détaché, posé sur une série qui ne compte pas, ou d'une valeur que plus
 * rien ne donne) n'est jamais annoncé par une reconstruction, qui le
 * descend : il reste au contrôle de nuit et à `--repair`, pour qu'on voie
 * d'où il vient. Un exercice dont un record porte un type que le modèle ne
 * lit pas (une casse que la collation confond avec un type suivi) est laissé
 * de côté : sa reconstruction lèverait au cast du type (`ValueError`), comme
 * toute reconstruction que l'application y tenterait, et arrêterait le
 * déploiement ; le contrôle « type des records » le signale.
 *
 * Une série validée dans l'instant du déploiement, dont la synchronisation
 * attend encore en file, ressemble à un tel écart : la migration lui donne
 * ses records sans les annoncer, comme le faisait `main`, et sa
 * synchronisation n'y trouvera plus rien à battre.
 *
 * Rejouable : un second passage ne trouve plus rien à reprendre.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $service = app(PersonalRecordService::class);
        $compte = null;

        $couples = DB::table('workout_lines')
            ->whereNotNull('user_id')
            ->select(['user_id', 'exercise_id'])
            ->distinct()
            ->orderBy('user_id')
            ->orderBy('exercise_id');

        foreach ($couples->lazy(500) as $couple) {
            $idUtilisateur = self::entier($couple->user_id ?? null);
            $idExercice = self::entier($couple->exercise_id ?? null);

            if (! $this->resteSousSaMeilleureSerie($service, $idUtilisateur, $idExercice)) {
                continue;
            }

            if ($compte?->id !== $idUtilisateur) {
                $compte = User::query()->find($idUtilisateur);
            }

            if ($compte instanceof User) {
                $service->reparer($compte, $idExercice);
            }
        }
    }

    /**
     * Volontairement vide : redescendre ces records les remettrait sous des
     * séries que leur synchronisation a déjà annoncées, et rien n'a gardé leur
     * valeur d'avant.
     */
    public function down(): void
    {
    }

    /**
     * Un record suivi de l'exercice manque, ou reste sous ce que donne la
     * meilleure série éligible ; faux pour un exercice sans série éligible, et
     * pour un exercice dont un record porte un type que le modèle ne lit pas.
     */
    private function resteSousSaMeilleureSerie(PersonalRecordService $service, int $idUtilisateur, int $idExercice): bool
    {
        $attendus = $service->recordsAttendus($idUtilisateur, $idExercice);

        if ($attendus === []) {
            return false;
        }

        /** @var array<string, float> $tenus */
        $tenus = [];

        $lignes = DB::table('personal_records')
            ->where('user_id', $idUtilisateur)
            ->where('exercise_id', $idExercice)
            ->orderBy('id')
            ->get(['type', 'value']);

        foreach ($lignes as $ligne) {
            $type = is_string($ligne->type ?? null) ? $ligne->type : '';

            if (PersonalRecordType::tryFrom($type) === null) {
                return false;
            }

            $tenus[$type] = is_numeric($ligne->value ?? null) ? (float) $ligne->value : 0.0;
        }

        foreach ($attendus as $type => $attendu) {
            if (! isset($tenus[$type]) || round($attendu['valeur'] - $tenus[$type], 2) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un identifiant lu dans une ligne brute.
     */
    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
};
