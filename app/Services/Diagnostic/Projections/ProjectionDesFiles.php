<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Les files et les jobs échoués, vus par le diagnostic (#2032).
 *
 * La liste rend l'état d'Horizon et les échecs des trente derniers jours,
 * groupés par classe de job et classe de l'exception levée. Le détail d'un
 * échec rend sa classe, sa file, sa date, la classe de l'exception levée et
 * sa pile, sans arguments ; avec `TexteBrut`, son message. L'exception levée
 * est celle du job, pas celle qu'elle enveloppe (`ExceptionDUnJobEchoue`).
 * Jamais la charge utile : la commande sérialisée, et les adresses d'une
 * notification, n'en sortent pas. La base n'en extrait que le nom affiché du
 * job, et de l'exception que les premiers octets de sa tête, de son dernier
 * bloc et, au détail, de la pile qui suit son message.
 */
final class ProjectionDesFiles
{
    public const int CADRES_AU_PLUS = 30;

    public const int BUDGET_DU_MESSAGE = 2_048;

    private const string NOM_DU_JOB = "case when json_valid(payload) then json_unquote(json_extract(payload, '$.displayName')) end as job";

    /**
     * @return array{horizon: array<string, mixed>, echecs: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $lignes = $this->lignes($fenetre);
        $groupes = new RegroupeLesOccurrences()(
            LecturesBornees::bornees($lignes),
            static fn (stdClass $ligne): string => (string) json_encode([$ligne->job, self::classeLevee($ligne)]),
            static fn (stdClass $ligne): array => [
                'job' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->job),
                'exception' => self::classeLevee($ligne),
                'dernier_uuid' => Vocabulaire::forme(Vocabulaire::UUID, $ligne->uuid),
            ],
        );

        return [
            'horizon' => new EtatDeHorizon()(),
            'echecs' => $groupes->take(LecturesBornees::GROUPES_AU_PLUS)->all(),
            ...LecturesBornees::bilan($lignes, $groupes),
        ];
    }

    /**
     * Le détail d'un échec des trente derniers jours, `null` s'il n'y est
     * pas.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $uuid, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $ligne = ExceptionDUnJobEchoue::avecLaPile(ExceptionDUnJobEchoue::lire(
            $this->echecs($fenetre)->select(['uuid', 'queue', 'failed_at'])->selectRaw(self::NOM_DU_JOB),
            ExceptionDUnJobEchoue::OCTETS_PAR_MORCEAU,
        ))
            ->where('uuid', $uuid)
            ->first();

        return match ($ligne) {
            null => null,
            default => $this->echec($ligne, ExceptionDUnJobEchoue::levee($ligne->tete_de_l_exception, $ligne->fin_de_l_exception), $texteBrut),
        };
    }

    /**
     * Les échecs à grouper, du plus récent au plus ancien, et un de plus que
     * la borne pour savoir si elle a débordé. De l'exception, seule la tête,
     * où est sa classe.
     *
     * @return Collection<int, stdClass>
     */
    private function lignes(FenetreDeDiagnostic $fenetre): Collection
    {
        return ExceptionDUnJobEchoue::lire(
            $this->echecs($fenetre)->select(['id', 'uuid', 'queue', 'failed_at as created_at'])->selectRaw(self::NOM_DU_JOB),
            ExceptionDUnJobEchoue::OCTETS_DE_LA_LISTE,
        )
            ->orderByDesc('id')
            ->limit(LecturesBornees::LIGNES_AU_PLUS + 1)
            ->get();
    }

    /**
     * La classe de l'exception que le job a levée, lue des premiers octets
     * de sa tête et de son dernier bloc.
     */
    private static function classeLevee(stdClass $ligne): ?string
    {
        return ExceptionDUnJobEchoue::classe(ExceptionDUnJobEchoue::levee($ligne->tete_de_l_exception, $ligne->fin_de_l_exception));
    }

    /**
     * Les échecs des trente derniers jours, quelle que soit la fenêtre
     * demandée : c'est la conservation de `failed_jobs` que le diagnostic
     * suppose.
     */
    private function echecs(FenetreDeDiagnostic $fenetre): Builder
    {
        $connexion = config('queue.failed.database');

        return DB::connection(is_string($connexion) ? $connexion : null)
            ->table(config()->string('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', $fenetre->jusqua->subDays(FenetreDeDiagnostic::JOURS_AU_PLUS));
    }

    /**
     * Le détail d'un échec, dont l'exception levée est donnée en texte ; sa
     * pile est celle que la base a lue après le message.
     *
     * @return array<string, mixed>
     */
    private function echec(stdClass $ligne, string $exception, bool $texteBrut): array
    {
        return [
            'uuid' => Vocabulaire::forme(Vocabulaire::UUID, $ligne->uuid),
            'job' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->job),
            'file' => Vocabulaire::forme(Vocabulaire::IDENTIFIANT, $ligne->queue),
            'echoue_le' => ValeurLue::instant($ligne->failed_at),
            'exception' => ExceptionDUnJobEchoue::classe($exception),
            'pile' => PileSansArguments::duTexte(ValeurLue::morceau($ligne->pile_de_l_exception), self::CADRES_AU_PLUS),
            ...match ($texteBrut) {
                true => ['non_fiable' => ['message' => new TexteNonFiable(ExceptionDUnJobEchoue::message($exception), self::BUDGET_DU_MESSAGE)]],
                false => [],
            },
        ];
    }
}
