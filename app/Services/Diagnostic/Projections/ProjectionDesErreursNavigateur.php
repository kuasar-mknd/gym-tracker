<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Models\ErreurNavigateur;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Les erreurs navigateur, vues par le diagnostic (#2032).
 *
 * La liste les groupe par empreinte : type, nombre, comptes touchés (un
 * nombre), familles de navigateurs (une liste fermée), fichier du build
 * (seulement s'il est du build en place), ligne, colonne. Le nombre compte
 * les occurrences gardées : une par compte et par heure au plus
 * (`ErreurNavigateur::SECONDES_DU_REGROUPEMENT`). Les groupes qui
 * touchent le plus de comptes passent devant, puis les plus nombreux, puis
 * les plus récents : un compte seul ne pousse pas son erreur devant celle
 * de plusieurs. Les erreurs d'un compte supprimé ou non vérifié ne sont
 * pas lues. Le détail d'un groupe ajoute,
 * avec `TexteBrut`, le message, la page (son chemin seul, sous un hôte
 * masqué, les identifiants numériques masqués) et les
 * premières lignes de la pile de sa dernière occurrence, sous `non_fiable`,
 * lus de cette seule ligne : le groupe en compte jusqu'à 5 000, et leurs
 * piles ne tiendraient pas dans la mémoire d'une requête. Jamais le compte,
 * l'URL complète ni la chaîne d'agent.
 */
final class ProjectionDesErreursNavigateur
{
    public const int LIGNES_DE_PILE = 20;

    public const int BUDGET_DE_LA_PILE = 2_048;

    public const int BUDGET_DU_MESSAGE = 1_024;

    public const int BUDGET_DE_LA_PAGE = 512;

    private const array COLONNES = ['e.id', 'e.user_id', 'e.type', 'e.source', 'e.ligne', 'e.colonne', 'e.agent', 'e.empreinte', 'e.created_at'];

    /**
     * @return array{groupes: array<int, array<string, mixed>>, lignes_lues: int, echantillon: bool, tronque: bool}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        $lignes = $this->erreurs($fenetre->depuis)->limit(LecturesBornees::LIGNES_AU_PLUS + 1)->get();
        $groupes = $this->regrouper(LecturesBornees::bornees($lignes));

        return [
            'groupes' => $groupes->take(LecturesBornees::GROUPES_AU_PLUS)->all(),
            ...LecturesBornees::bilan($lignes, $groupes),
        ];
    }

    /**
     * Le détail d'un groupe de la fenêtre de la liste (vingt-quatre heures),
     * `null` s'il n'y a rien : une fenêtre plus large laisserait un jeton
     * sans `TexteBrut` confirmer une empreinte devinée sur des jours que la
     * liste ne montre pas. Comme la liste, il compte au plus 5 000 occurrences, en lit une
     * de plus et dit s'il a débordé (`echantillon`).
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $empreinte, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $lignes = $this->erreurs($fenetre->depuis)
            ->where('e.empreinte', Vocabulaire::forme(Vocabulaire::EMPREINTE, $empreinte))
            ->limit(LecturesBornees::LIGNES_AU_PLUS + 1)
            ->get();

        return match ($lignes->isEmpty()) {
            true => null,
            false => [
                ...$this->regrouper(LecturesBornees::bornees($lignes))->firstOrFail(),
                'echantillon' => LecturesBornees::deborde($lignes),
                ...$this->texte($lignes->firstOrFail()->id, $texteBrut),
            ],
        };
    }

    /**
     * Les erreurs des comptes vérifiés, de la plus récente à la plus
     * ancienne, par leurs seules colonnes nommées et sous la borne de temps
     * de la base : l'indication vit dans le `select`, posé avant le reste.
     */
    private function erreurs(CarbonImmutable $depuis): Builder
    {
        return LecturesBornees::requete(ErreurNavigateur::query()->toBase()->from(new ErreurNavigateur()->getTable(), 'e'), self::COLONNES)
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereNotNull('u.email_verified_at')
            ->where('e.created_at', '>=', $depuis)
            ->orderByDesc('e.id');
    }

    /**
     * @param  Collection<int, stdClass>  $lignes
     * @return Collection<int, array<string, mixed>>
     */
    private function regrouper(Collection $lignes): Collection
    {
        return new RegroupeLesOccurrences()(
            $lignes,
            static fn (stdClass $ligne): string => ValeurLue::texte($ligne->empreinte),
            static fn (stdClass $derniere, Collection $toutes): array => [
                'empreinte' => Vocabulaire::forme(Vocabulaire::EMPREINTE, $derniere->empreinte),
                'type' => Vocabulaire::parmi($derniere->type, ErreurNavigateur::TYPES),
                'comptes_touches' => $toutes->pluck('user_id')->unique()->count(),
                'navigateurs' => $toutes->map(static fn (stdClass $ligne): string => FamilleDeNavigateur::de($ligne->agent))->unique()->sort()->values()->all(),
                'fichier' => Vocabulaire::fichierDuBuild($derniere->source),
                'ligne' => ValeurLue::entier($derniere->ligne),
                'colonne' => ValeurLue::entier($derniere->colonne),
            ],
            ['comptes_touches', 'nombre'],
        );
    }

    /**
     * Sous `TexteBrut`, le texte de la dernière occurrence, lu de sa seule
     * ligne.
     *
     * @return array<string, mixed>
     */
    private function texte(mixed $identifiant, bool $texteBrut): array
    {
        return match ($texteBrut) {
            true => ['non_fiable' => $this->texteDe(LecturesBornees::requete(ErreurNavigateur::query()->toBase(), ['message', 'url', 'pile'])->where('id', $identifiant)->first())],
            false => [],
        };
    }

    /**
     * Le message, la page (son chemin, sous un hôte remplacé par un marqueur) et les premières lignes de la pile
     * d'une occurrence ; rien d'une occurrence effacée entre deux
     * lectures.
     *
     * @return array<string, TexteNonFiable>
     */
    private function texteDe(?stdClass $occurrence): array
    {
        return [
            'message' => new TexteNonFiable(ValeurLue::texte($occurrence?->message), self::BUDGET_DU_MESSAGE),
            'page' => new TexteNonFiable(self::page(ValeurLue::texte($occurrence?->url)), self::BUDGET_DE_LA_PAGE),
            'pile' => new TexteNonFiable(implode("\n", array_slice(explode("\n", ValeurLue::texte($occurrence?->pile)), 0, self::LIGNES_DE_PILE)), self::BUDGET_DE_LA_PILE),
        ];
    }

    /**
     * Le chemin d'une page sous un hôte masqué (`//[hôte]/chemin`) : ni
     * schéma, ni compte (`user:secret@`), ni port, ni chaîne de requête, ni
     * fragment. Le nom d'hôte ne sort jamais : le masquer par motif
     * (`MasqueLesCoordonnees`) laisserait passer un nom à un seul label ou une
     * adresse en notation exotique. Sans hôte, seul un chemin absolu reste,
     * que l'expurgation (`TexteNonFiable`) réduit encore (`/seances/3` rend
     * `[chemin][n]`) ; tout autre texte ne rend rien.
     */
    private static function page(string $url): string
    {
        $adresse = parse_url($url);
        $chemin = is_array($adresse) ? self::chemin($adresse['path'] ?? '') : '';

        return match (true) {
            ! is_array($adresse) => '',
            isset($adresse['host']) => '//[hôte]'.$chemin,
            default => str_starts_with($chemin, '/') ? $chemin : '',
        };
    }

    /**
     * Un chemin segment par segment. Une adresse collée dans le chemin
     * (`/x/http://hote/y`) est coupée à son premier `//`.
     */
    private static function chemin(string $chemin): string
    {
        return implode('/', array_map(self::segment(...), explode('/', (string) preg_replace('#//.*$#s', '', $chemin))));
    }

    /**
     * Un segment, lu après le décodage des `%xx` et sans caractère invisible
     * (sinon `%E2%80%8B12` ne passerait pas pour un nombre) : décodé, un
     * segment qui contient `/`, une barre oblique inverse, `:` ou `@` devient
     * `[segment]`, un identifiant numérique, avec ou sans extension (`12`,
     * `12.json`), devient `[n]`.
     */
    private static function segment(string $segment): string
    {
        $lu = preg_replace('/[\p{C}\p{Z}\p{M}]/u', '', rawurldecode($segment));

        return match (true) {
            $lu === null, preg_match('~[/\\\\:@]~', $lu) === 1 => '[segment]',
            preg_match('/^\d+(\.\w+)?$/', $lu) === 1 => '[n]',
            default => $lu,
        };
    }
}
