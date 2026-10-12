<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Models\TachePlanifiee;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Support\Collection;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;
use stdClass;

/**
 * Les tâches planifiées, vues par le diagnostic (#2032) : commande, cron,
 * état, dates, marge, et les échecs des sept derniers jours (date, durée,
 * code de sortie). Au détail d'une tâche, avec `TexteBrut`, le message de
 * chaque échec, sous `non_fiable`. Jamais la sortie d'une commande, que le
 * moniteur garde aussi : elle n'est pas lue.
 *
 * L'état de chaque tâche est jugé à l'instant de la lecture, le même pour
 * toutes.
 */
final class ProjectionDesTachesPlanifiees
{
    public const int JOURS_D_ECHECS = 7;

    public const int BUDGET_DU_MESSAGE = 512;

    /** @var array<string, string> */
    public const array ETATS = [
        TachePlanifiee::A_L_HEURE => 'a_l_heure',
        TachePlanifiee::EN_RETARD => 'en_retard',
        TachePlanifiee::ECHOUEE => 'echouee',
    ];

    private const array COLONNES = ['id', 'name', 'cron_expression', 'timezone', 'grace_time_in_minutes', 'last_started_at', 'last_finished_at', 'last_failed_at', 'created_at'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $taches = TachePlanifiee::query()->select(self::COLONNES)->orderBy('name')->limit(LecturesBornees::GROUPES_AU_PLUS)->get();
        $echecs = $this->echecs($fenetre, $taches->modelKeys(), false);

        return $taches
            ->map(fn (TachePlanifiee $tache): array => $this->tache($tache, $fenetre, $echecs[ValeurLue::entier($tache->getKey()) ?? 0] ?? []))
            ->values()
            ->all();
    }

    /**
     * Le détail d'une tâche, `null` si le moniteur ne la connaît pas.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $identifiant, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $tache = TachePlanifiee::query()->select(self::COLONNES)->find($identifiant);

        return match ($tache) {
            null => null,
            default => $this->tache($tache, $fenetre, $this->echecs($fenetre, [$identifiant], $texteBrut)[$identifiant] ?? []),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $echecs
     * @return array<string, mixed>
     */
    private function tache(TachePlanifiee $tache, FenetreDeDiagnostic $fenetre, array $echecs): array
    {
        return [
            'id' => ValeurLue::entier($tache->getKey()),
            'commande' => Vocabulaire::forme(Vocabulaire::COMMANDE, $tache->name),
            'cron' => Vocabulaire::forme(Vocabulaire::CRON, $tache->cron_expression),
            'etat' => self::ETATS[$tache->etat($fenetre->jusqua)] ?? null,
            'dernier_depart_le' => ValeurLue::instant($tache->last_started_at),
            'derniere_fin_le' => ValeurLue::instant($tache->last_finished_at),
            'dernier_echec_le' => ValeurLue::instant($tache->last_failed_at),
            'marge_en_minutes' => ValeurLue::entier($tache->grace_time_in_minutes),
            'echecs_sur_7_jours' => $echecs,
        ];
    }

    /**
     * Les échecs des sept derniers jours des tâches données, rangés par
     * tâche, du plus récent au plus ancien. La base n'extrait du journal que
     * la durée, le code de sortie et, sous `TexteBrut`, le message.
     *
     * @param  array<int, int|string>  $taches
     * @return array<array-key, array<int, array<string, mixed>>>
     */
    private function echecs(FenetreDeDiagnostic $fenetre, array $taches, bool $texteBrut): array
    {
        return MonitoredScheduledTaskLogItem::query()->toBase()
            ->select(['id', 'monitored_scheduled_task_id', 'created_at'])
            ->selectRaw("json_unquote(json_extract(meta, '$.runtime')) as duree")
            ->selectRaw("json_unquote(json_extract(meta, '$.exit_code')) as code_de_sortie")
            ->when($texteBrut, static fn ($requete) => $requete->selectRaw("json_unquote(json_extract(meta, '$.failure_message')) as message"))
            ->whereIn('monitored_scheduled_task_id', $taches)
            ->where('type', MonitoredScheduledTaskLogItem::TYPE_FAILED)
            ->where('created_at', '>=', $fenetre->jusqua->subDays(self::JOURS_D_ECHECS))
            ->orderByDesc('id')
            ->limit(LecturesBornees::LIGNES_AU_PLUS)
            ->get()
            ->groupBy('monitored_scheduled_task_id')
            ->map(static fn (Collection $lignes): array => $lignes->map(static fn (stdClass $ligne): array => self::echec($ligne, $texteBrut))->values()->all())
            ->all();
    }

    /**
     * Un échec : sa date, sa durée, son code de sortie et, sous `TexteBrut`,
     * son message.
     *
     * @return array<string, mixed>
     */
    private static function echec(stdClass $ligne, bool $texteBrut): array
    {
        return [
            'le' => ValeurLue::instant($ligne->created_at),
            'duree_en_secondes' => ValeurLue::duree($ligne->duree),
            'code_de_sortie' => ValeurLue::entier($ligne->code_de_sortie),
            ...match ($texteBrut) {
                true => ['non_fiable' => ['message' => new TexteNonFiable(ValeurLue::texte($ligne->message), self::BUDGET_DU_MESSAGE)]],
                false => [],
            },
        ];
    }
}
