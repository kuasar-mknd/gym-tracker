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
 * (seulement s'il est du build en place), ligne, colonne. Les groupes qui
 * touchent le plus de comptes passent devant, puis les plus nombreux, puis
 * les plus récents : un compte seul ne pousse pas son erreur devant celle
 * de plusieurs. Les erreurs d'un compte supprimé ou non vérifié ne sont
 * pas lues. Le détail d'un groupe ajoute,
 * avec `TexteBrut`, le message, la page sans sa chaîne de requête et les
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
        $lignes = $this->erreurs($fenetre->depuis)->select(self::COLONNES)->limit(LecturesBornees::LIGNES_AU_PLUS + 1)->get();
        $groupes = $this->regrouper(LecturesBornees::bornees($lignes));

        return [
            'groupes' => $groupes->take(LecturesBornees::GROUPES_AU_PLUS)->all(),
            ...LecturesBornees::bilan($lignes, $groupes),
        ];
    }

    /**
     * Le détail d'un groupe des trente derniers jours, `null` s'il n'y a
     * rien. Comme la liste, il compte au plus 5 000 occurrences, en lit une
     * de plus et dit s'il a débordé (`echantillon`).
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $empreinte, FenetreDeDiagnostic $fenetre, bool $texteBrut): ?array
    {
        $lignes = $this->erreurs($fenetre->jusqua->subDays(FenetreDeDiagnostic::JOURS_AU_PLUS))
            ->select(self::COLONNES)
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
     * ancienne.
     */
    private function erreurs(CarbonImmutable $depuis): Builder
    {
        return ErreurNavigateur::query()->toBase()
            ->from(new ErreurNavigateur()->getTable(), 'e')
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
            true => ['non_fiable' => $this->texteDe(ErreurNavigateur::query()->toBase()->select(['message', 'url', 'pile'])->where('id', $identifiant)->first())],
            false => [],
        };
    }

    /**
     * Le message, la page sans sa chaîne de requête et les premières lignes
     * de la pile d'une occurrence ; rien d'une occurrence effacée entre deux
     * lectures.
     *
     * @return array<string, TexteNonFiable>
     */
    private function texteDe(?stdClass $occurrence): array
    {
        return [
            'message' => new TexteNonFiable(ValeurLue::texte($occurrence?->message), self::BUDGET_DU_MESSAGE),
            'page' => new TexteNonFiable((string) preg_replace('/[?#].*$/s', '', ValeurLue::texte($occurrence?->url)), self::BUDGET_DE_LA_PAGE),
            'pile' => new TexteNonFiable(implode("\n", array_slice(explode("\n", ValeurLue::texte($occurrence?->pile)), 0, self::LIGNES_DE_PILE)), self::BUDGET_DE_LA_PILE),
        ];
    }
}
