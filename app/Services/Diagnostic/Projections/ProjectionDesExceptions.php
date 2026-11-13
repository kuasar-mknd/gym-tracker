<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Les exceptions enregistrées, vues par le diagnostic (#2032).
 *
 * La liste groupe par type, fichier et ligne les exceptions de la fenêtre :
 * nombre, première et dernière occurrence, dernier identifiant, nom de
 * route. Le détail d'une exception donne son type, son code, son fichier,
 * sa ligne, sa méthode, sa route, le gabarit de son chemin et sa pile, sans
 * arguments ; avec `TexteBrut`, son message et son chemin brut, sous
 * `non_fiable`. Jamais l'adresse IP, les en-têtes, le corps, les cookies,
 * les paramètres de route, les requêtes SQL ni le Markdown : ces colonnes ne
 * sont pas lues.
 */
final class ProjectionDesExceptions
{
    public const int CADRES_AU_PLUS = 30;

    public const int BUDGET_DU_MESSAGE = 2_048;

    public const int BUDGET_DU_CHEMIN = 512;

    public const int OCCURRENCES_PAR_PAGE = 25;

    /** Le plus grand identifiant qu'un curseur admet, sous la capacité d'un entier de la base. */
    public const int IDENTIFIANT_AU_PLUS = 999_999_999_999_999_999;

    /** @var list<string> */
    public const array METHODES = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @return array{groupes: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $lignes = LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id', 'type', 'file', 'line', 'created_at'])
            ->where('created_at', '>=', $fenetre->depuis)
            ->orderByDesc('id')
            ->limit(LecturesBornees::LIGNES_AU_PLUS + 1)
            ->get();

        $groupes = new RegroupeLesOccurrences()(
            LecturesBornees::bornees($lignes),
            static fn (stdClass $ligne): string => (string) json_encode([$ligne->type, $ligne->file, $ligne->line]),
            static fn (stdClass $ligne): array => [
                'type' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->type),
                'fichier' => Vocabulaire::fichier($ligne->file),
                'ligne' => ValeurLue::entier($ligne->line),
            ],
        );

        return [
            'groupes' => $this->avecLeurRoute($groupes->take(LecturesBornees::GROUPES_AU_PLUS)),
            ...LecturesBornees::bilan($lignes, $groupes),
        ];
    }

    /**
     * Le détail d'une exception de la fenêtre de conservation, `null` si
     * elle n'y est pas.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $identifiant, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $ligne = LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id', 'type', 'code', 'file', 'line', 'method', 'trace', 'route_context', 'created_at'])
            ->when($texteBrut, static fn ($requete) => $requete->addSelect(['message', 'path']))
            ->where('id', $identifiant)
            ->where('created_at', '>=', $fenetre->jusqua->subDays(FenetreDeDiagnostic::JOURS_AU_PLUS))
            ->first();

        return match ($ligne) {
            null => null,
            default => [...$this->structure($ligne), ...$this->texte($ligne, $texteBrut)],
        };
    }

    /**
     * Une page des occurrences du groupe (type, fichier, ligne) de
     * l'exception donnée, de la plus récente à la plus ancienne, sur trente
     * jours et sur les 5 000 dernières lignes de la fenêtre seulement :
     * `echantillon` dit que des plus anciennes n'ont pas été lues. `suivant`
     * est le curseur `avant` de la page d'après, `null` à la dernière.
     * `null` si l'exception n'est pas dans la fenêtre de conservation.
     *
     * @return array{occurrences: list<array<string, mixed>>, suivant: int|null, echantillon: bool}|null
     */
    public function occurrences(int $identifiant, ?int $avant, FenetreDeDiagnostic $fenetre): ?array
    {
        $depuis = $fenetre->jusqua->subDays(FenetreDeDiagnostic::JOURS_AU_PLUS);
        $groupe = LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['type', 'file', 'line'])
            ->where('id', $identifiant)
            ->where('created_at', '>=', $depuis)
            ->first();

        if (! $groupe instanceof stdClass) {
            return null;
        }

        $plancher = $this->plancher($depuis);
        $page = $this->page($groupe, $depuis, $plancher, $avant);
        $rendues = $page->take(self::OCCURRENCES_PAR_PAGE);

        return [
            'occurrences' => array_values($rendues->map(static fn (stdClass $ligne): array => [
                'id' => ValeurLue::entier($ligne->id),
                'le' => ValeurLue::instant($ligne->created_at),
                'methode' => Vocabulaire::parmi($ligne->method, self::METHODES),
                'route' => NomDeRoute::du($ligne->route_context),
            ])->all()),
            'suivant' => $page->count() > self::OCCURRENCES_PAR_PAGE ? ValeurLue::entier($rendues->last()?->id) : null,
            'echantillon' => $plancher !== null && $this->plusAncienneQueLePlancher($groupe, $depuis, $plancher),
        ];
    }

    /**
     * L'identifiant de la 5 000e ligne la plus récente de la fenêtre, `null`
     * si elle en compte moins : en deçà, rien n'est lu.
     */
    private function plancher(CarbonImmutable $depuis): ?int
    {
        $dernieres = LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id'])
            ->where('created_at', '>=', $depuis)
            ->orderByDesc('id')
            ->offset(LecturesBornees::LIGNES_AU_PLUS - 1)
            ->first();

        return $dernieres instanceof stdClass ? ValeurLue::entier($dernieres->id) : null;
    }

    /**
     * Une page du groupe, une ligne de plus que sa taille pour savoir s'il
     * en reste.
     *
     * @return Collection<int, stdClass>
     */
    private function page(stdClass $groupe, CarbonImmutable $depuis, ?int $plancher, ?int $avant): Collection
    {
        return LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id', 'method', 'route_context', 'created_at'])
            ->where('type', $groupe->type)
            ->where('file', $groupe->file)
            ->where('line', $groupe->line)
            ->where('created_at', '>=', $depuis)
            ->when($plancher !== null, static fn ($requete) => $requete->where('id', '>=', $plancher))
            ->when($avant !== null, static fn ($requete) => $requete->where('id', '<', $avant))
            ->orderByDesc('id')
            ->limit(self::OCCURRENCES_PAR_PAGE + 1)
            ->get();
    }

    /**
     * Le plancher a-t-il écarté des lignes du groupe ?
     */
    private function plusAncienneQueLePlancher(stdClass $groupe, CarbonImmutable $depuis, int $plancher): bool
    {
        return LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id'])
            ->where('type', $groupe->type)
            ->where('file', $groupe->file)
            ->where('line', $groupe->line)
            ->where('created_at', '>=', $depuis)
            ->where('id', '<', $plancher)
            ->first() instanceof stdClass;
    }

    /**
     * @return array<string, mixed>
     */
    private function structure(stdClass $ligne): array
    {
        $route = NomDeRoute::du($ligne->route_context);

        return [
            'id' => ValeurLue::entier($ligne->id),
            'type' => Vocabulaire::forme(Vocabulaire::CLASSE, $ligne->type),
            'code' => Vocabulaire::forme(Vocabulaire::CODE, $ligne->code),
            'fichier' => Vocabulaire::fichier($ligne->file),
            'ligne' => ValeurLue::entier($ligne->line),
            'methode' => Vocabulaire::parmi($ligne->method, self::METHODES),
            'route' => $route,
            'gabarit' => NomDeRoute::gabarit($route),
            'le' => ValeurLue::instant($ligne->created_at),
            'pile' => PileSansArguments::duJson($ligne->trace, self::CADRES_AU_PLUS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function texte(stdClass $ligne, bool $texteBrut): array
    {
        return match ($texteBrut) {
            true => [
                'non_fiable' => [
                    'message' => new TexteNonFiable(ValeurLue::texte($ligne->message), self::BUDGET_DU_MESSAGE),
                    'chemin' => new TexteNonFiable(ValeurLue::texte($ligne->path), self::BUDGET_DU_CHEMIN),
                ],
            ],
            false => [],
        };
    }

    /**
     * Chaque groupe et le nom de route de sa dernière occurrence, lus d'une
     * seule requête sur les identifiants des groupes rendus.
     *
     * @param  Collection<int, array<string, mixed>>  $groupes
     * @return array<int, array<string, mixed>>
     */
    private function avecLeurRoute(Collection $groupes): array
    {
        $routes = LecturesBornees::requete(ExceptionEnregistree::query()->toBase(), ['id', 'route_context'])
            ->whereIn('id', $groupes->pluck('dernier_id')->all())
            ->pluck('route_context', 'id');

        return $groupes
            ->map(static fn (array $groupe): array => [...$groupe, 'route' => NomDeRoute::du($routes->get(ValeurLue::entier($groupe['dernier_id'])))])
            ->values()
            ->all();
    }
}
