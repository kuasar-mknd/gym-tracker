<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Models\TachePlanifiee;
use App\Services\Diagnostic\ExpurgeLeTexte;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;
use stdClass;

/**
 * Les tâches planifiées, vues par le diagnostic (#2032) : commande, cron,
 * état, dates, marge, le nombre des échecs des sept derniers jours et les
 * derniers d'entre eux (date, durée, code de sortie) : cinq par tâche au
 * résumé, cinquante au détail d'une tâche, qui ajoute sous `TexteBrut` le
 * message de chacun, sous `non_fiable`, lu de ces seules lignes et coupé à
 * ce que l'expurgation en garde. Jamais la sortie d'une commande, que le
 * moniteur garde aussi : elle n'est pas lue.
 *
 * Comme les autres listes, le résumé lit au plus 50 tâches et 5 000 échecs,
 * un de plus pour savoir s'il a débordé, et le dit (`tronque`,
 * `echantillon`) : une tâche de chaque minute qui échoue une semaine durant
 * en laisse plus de 10 000. Le détail d'une tâche le dit aussi
 * (`echantillon`). L'état de chaque tâche est jugé à l'instant de la
 * lecture, le même pour toutes.
 */
final class ProjectionDesTachesPlanifiees
{
    public const int JOURS_D_ECHECS = 7;

    public const int BUDGET_DU_MESSAGE = 512;

    /** Les derniers échecs que le résumé montre de chaque tâche. */
    public const int ECHECS_DU_RESUME = 5;

    /** @var array<string, string> */
    public const array ETATS = [
        TachePlanifiee::A_L_HEURE => 'a_l_heure',
        TachePlanifiee::EN_RETARD => 'en_retard',
        TachePlanifiee::ECHOUEE => 'echouee',
    ];

    private const array COLONNES = ['id', 'name', 'cron_expression', 'timezone', 'grace_time_in_minutes', 'last_started_at', 'last_finished_at', 'last_failed_at', 'created_at'];

    /**
     * @return array{taches: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $taches = $this->taches()->orderBy('name')->limit(LecturesBornees::GROUPES_AU_PLUS + 1)->get();
        $montrees = $taches->take(LecturesBornees::GROUPES_AU_PLUS);
        $echecs = $this->echecs($fenetre, $montrees->modelKeys());
        $parTache = LecturesBornees::bornees($echecs)->groupBy('monitored_scheduled_task_id');
        $this->mesurer($parTache->flatMap(static fn (Collection $de): Collection => $de->take(self::ECHECS_DU_RESUME)));

        return [
            'taches' => $montrees
                ->map(fn (TachePlanifiee $tache): array => $this->tache($tache, $fenetre, $parTache->get(ValeurLue::entier($tache->getKey()) ?? 0, new Collection()), self::ECHECS_DU_RESUME, false))
                ->values()
                ->all(),
            ...LecturesBornees::bilan($echecs, $taches),
        ];
    }

    /**
     * Le détail d'une tâche, `null` si le moniteur ne la connaît pas. Il
     * compte au plus 5 000 échecs, et dit s'il en a trouvé plus
     * (`echantillon`).
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $identifiant, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $tache = $this->taches()->whereKey($identifiant)->first();

        return match ($tache) {
            null => null,
            default => $this->detailDe($tache, $fenetre, $this->echecs($fenetre, [$identifiant]), $texteBrut),
        };
    }

    /**
     * Les tâches, par leurs seules colonnes nommées, sous la borne de temps
     * de la base : l'indication vit dans le `select` de la requête de base,
     * que le modèle partage.
     *
     * @return Builder<TachePlanifiee>
     */
    private function taches(): Builder
    {
        $requete = TachePlanifiee::query();
        LecturesBornees::requete($requete->getQuery(), self::COLONNES);

        return $requete;
    }

    /**
     * Le détail d'une tâche : ses 5 000 derniers échecs comptés, les
     * cinquante derniers montrés, et si la lecture a débordé.
     *
     * @param  Collection<int, stdClass>  $echecs  du plus récent au plus ancien, un de plus que la borne
     * @return array<string, mixed>
     */
    private function detailDe(TachePlanifiee $tache, FenetreDeDiagnostic $fenetre, Collection $echecs, bool $texteBrut): array
    {
        $this->mesurer(LecturesBornees::bornees($echecs)->take(LecturesBornees::GROUPES_AU_PLUS));

        return [
            ...$this->tache($tache, $fenetre, LecturesBornees::bornees($echecs), LecturesBornees::GROUPES_AU_PLUS, $texteBrut),
            'echantillon' => LecturesBornees::deborde($echecs),
        ];
    }

    /**
     * Une tâche, le nombre de ses échecs lus et les `$montres` derniers.
     *
     * @param  Collection<int, stdClass>  $echecs  du plus récent au plus ancien
     * @return array<string, mixed>
     */
    private function tache(TachePlanifiee $tache, FenetreDeDiagnostic $fenetre, Collection $echecs, int $montres, bool $texteBrut): array
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
            'echecs_sur_7_jours' => $echecs->count(),
            'derniers_echecs' => $this->derniers($echecs->take($montres), $texteBrut),
        ];
    }

    /**
     * Les échecs des sept derniers jours des tâches données, du plus récent
     * au plus ancien, un de plus que la borne pour savoir si elle a débordé.
     * La base n'extrait du journal que la durée et le code de sortie.
     *
     * @param  array<int, int|string>  $taches
     * @return Collection<int, stdClass>
     */
    private function echecs(FenetreDeDiagnostic $fenetre, array $taches): Collection
    {
        return LecturesBornees::requete(MonitoredScheduledTaskLogItem::query()->toBase(), ['id', 'monitored_scheduled_task_id', 'created_at'])
            ->whereIn('monitored_scheduled_task_id', $taches)
            ->where('type', MonitoredScheduledTaskLogItem::TYPE_FAILED)
            ->where('created_at', '>=', $fenetre->jusqua->subDays(self::JOURS_D_ECHECS))
            ->orderByDesc('id')
            ->limit(LecturesBornees::LIGNES_AU_PLUS + 1)
            ->get();
    }

    /**
     * La durée et le code de sortie des échecs montrés, extraits en base de
     * ces seules lignes et posés sur elles : le tri des 5 000 lignes lues ne
     * porte ainsi aucune extraction de la colonne `meta`, que le moniteur
     * laisse grossir avec le message.
     *
     * @param  Collection<int, stdClass>  $montres
     */
    private function mesurer(Collection $montres): void
    {
        if ($montres->isEmpty()) {
            return;
        }

        $mesures = LecturesBornees::requete(MonitoredScheduledTaskLogItem::query()->toBase(), ['id'])
            ->selectRaw("json_unquote(json_extract(meta, '$.runtime')) as duree")
            ->selectRaw("json_unquote(json_extract(meta, '$.exit_code')) as code_de_sortie")
            ->whereIn('id', $montres->pluck('id')->all())
            ->get()
            ->keyBy('id');

        foreach ($montres as $echec) {
            $mesure = $mesures->get(ValeurLue::entier($echec->id) ?? 0);
            $echec->duree = $mesure->duree ?? null;
            $echec->code_de_sortie = $mesure->code_de_sortie ?? null;
        }
    }

    /**
     * Les échecs montrés et, sous `TexteBrut`, le message de chacun, lu de
     * ces seules lignes.
     *
     * @param  Collection<int, stdClass>  $echecs
     * @return array<int, array<string, mixed>>
     */
    private function derniers(Collection $echecs, bool $texteBrut): array
    {
        $messages = match ($texteBrut && $echecs->isNotEmpty()) {
            true => $this->messages($echecs->pluck('id')->all()),
            false => null,
        };

        return $echecs->map(static fn (stdClass $ligne): array => self::echec($ligne, $messages))->values()->all();
    }

    /**
     * Le message de chacun des échecs donnés, par identifiant : les 8 Kio
     * que l'expurgation en garderait, coupés en base en octets.
     *
     * @param  array<array-key, mixed>  $identifiants
     * @return array<array-key, mixed>
     */
    private function messages(array $identifiants): array
    {
        return LecturesBornees::requete(MonitoredScheduledTaskLogItem::query()->toBase(), ['id'])
            ->selectRaw("substring(cast(json_unquote(json_extract(meta, '$.failure_message')) as binary), 1, ?) as message", [ExpurgeLeTexte::OCTETS_LUS])
            ->whereIn('id', $identifiants)
            ->pluck('message', 'id')
            ->all();
    }

    /**
     * Un échec : sa date, sa durée, son code de sortie et, quand les
     * messages sont lus, son message.
     *
     * @param  array<array-key, mixed>|null  $messages
     * @return array<string, mixed>
     */
    private static function echec(stdClass $ligne, ?array $messages): array
    {
        return [
            'le' => ValeurLue::instant($ligne->created_at),
            'duree_en_secondes' => ValeurLue::duree($ligne->duree),
            'code_de_sortie' => ValeurLue::entier($ligne->code_de_sortie),
            ...match ($messages) {
                null => [],
                default => ['non_fiable' => ['message' => new TexteNonFiable(ValeurLue::morceau($messages[ValeurLue::entier($ligne->id) ?? 0] ?? null), self::BUDGET_DU_MESSAGE)]],
            },
        ];
    }
}
