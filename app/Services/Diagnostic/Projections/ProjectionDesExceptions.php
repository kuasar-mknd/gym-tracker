<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
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

    /** @var list<string> */
    public const array METHODES = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @return array{groupes: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $lignes = ExceptionEnregistree::query()->toBase()
            ->select(['id', 'type', 'file', 'line', 'created_at'])
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
        $ligne = ExceptionEnregistree::query()->toBase()
            ->select(['id', 'type', 'code', 'file', 'line', 'method', 'trace', 'route_context', 'created_at'])
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
        $routes = ExceptionEnregistree::query()->toBase()
            ->select(['id', 'route_context'])
            ->whereIn('id', $groupes->pluck('dernier_id')->all())
            ->pluck('route_context', 'id');

        return $groupes
            ->map(static fn (array $groupe): array => [...$groupe, 'route' => NomDeRoute::du($routes->get(ValeurLue::entier($groupe['dernier_id']) ?? 0))])
            ->values()
            ->all();
    }
}
