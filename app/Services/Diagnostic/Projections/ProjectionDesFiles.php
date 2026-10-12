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
 * groupés par classe de job et classe d'exception. Le détail d'un échec rend
 * sa classe, sa file, sa date, la classe de son exception et sa pile, sans
 * arguments ; avec `TexteBrut`, le message de l'exception. Jamais la charge
 * utile : la commande sérialisée, et les adresses d'une notification, n'en
 * sortent pas. La base n'en extrait que le nom affiché du job, et de
 * l'exception que ses premiers octets.
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
            static fn (stdClass $ligne): string => (string) json_encode([$ligne->job, ExceptionDUnJobEchoue::classe($ligne->debut_de_l_exception)]),
            static fn (stdClass $ligne): array => [
                'job' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->job),
                'exception' => ExceptionDUnJobEchoue::classe($ligne->debut_de_l_exception),
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
        $ligne = $this->echecs($fenetre)
            ->select(['uuid', 'queue', 'failed_at'])
            ->selectRaw(self::NOM_DU_JOB)
            ->selectRaw('substring(exception, 1, '.ExceptionDUnJobEchoue::OCTETS_LUS.') as exception')
            ->where('uuid', $uuid)
            ->first();

        return match ($ligne) {
            null => null,
            default => [
                'uuid' => Vocabulaire::forme(Vocabulaire::UUID, $ligne->uuid),
                'job' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->job),
                'file' => Vocabulaire::forme(Vocabulaire::IDENTIFIANT, $ligne->queue),
                'echoue_le' => ValeurLue::instant($ligne->failed_at),
                'exception' => ExceptionDUnJobEchoue::classe($ligne->exception),
                'pile' => PileSansArguments::duTexte(ValeurLue::texte($ligne->exception), self::CADRES_AU_PLUS),
                ...$this->texte($ligne, $texteBrut),
            ],
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
        return $this->echecs($fenetre)
            ->select(['id', 'uuid', 'queue', 'failed_at as created_at'])
            ->selectRaw(self::NOM_DU_JOB)
            ->selectRaw('substring(exception, 1, 512) as debut_de_l_exception')
            ->orderByDesc('id')
            ->limit(LecturesBornees::LIGNES_AU_PLUS + 1)
            ->get();
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
     * @return array<string, mixed>
     */
    private function texte(stdClass $ligne, bool $texteBrut): array
    {
        return match ($texteBrut) {
            true => ['non_fiable' => ['message' => new TexteNonFiable(ExceptionDUnJobEchoue::message($ligne->exception), self::BUDGET_DU_MESSAGE)]],
            false => [],
        };
    }
}
