<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Illuminate\Support\Collection;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/**
 * Le dernier passage des contrôles de santé, vu par le diagnostic (#2032) :
 * son âge, et chaque contrôle (nom, libellé, statut). Au détail, avec
 * `TexteBrut`, le résumé et le message de chaque contrôle, sous `non_fiable`.
 * Jamais les `meta`, qui citent des réglages et des chemins.
 *
 * Le passage est lu dans le magasin des résultats, jamais relancé : un
 * diagnostic qui lancerait `health:check` écrirait, et coûterait ce que
 * coûtent les contrôles.
 */
final class ProjectionDeLaSante
{
    /** @var list<string> */
    public const array STATUTS = ['ok', 'warning', 'failed', 'crashed', 'skipped'];

    public const int BUDGET_DU_RESUME = 255;

    public const int BUDGET_DU_MESSAGE = 1_024;

    /**
     * @return array{dernier_passage_le: string|null, age_en_secondes: int|null, controles: array<int, array<string, mixed>>}
     */
    public function liste(FenetreDeDiagnostic $fenetre): array
    {
        return $this->passage($fenetre, false);
    }

    /**
     * Le détail de la santé : la liste, et sous `TexteBrut` le texte de
     * chaque contrôle.
     *
     * @return array{dernier_passage_le: string|null, age_en_secondes: int|null, controles: array<int, array<string, mixed>>}
     */
    public function detail(FenetreDeDiagnostic $fenetre, bool $texteBrut): array
    {
        return $this->passage($fenetre, $texteBrut);
    }

    /**
     * @return array{dernier_passage_le: string|null, age_en_secondes: int|null, controles: array<int, array<string, mixed>>}
     */
    private function passage(FenetreDeDiagnostic $fenetre, bool $texteBrut): array
    {
        $passage = rescue(static fn (): ?StoredCheckResults => app(ResultStore::class)->latestResults(), null, report: false);
        /** @var Collection<int, StoredCheckResult> $controles */
        $controles = $passage->storedCheckResults ?? new Collection();

        return [
            'dernier_passage_le' => ValeurLue::instant($passage?->finishedAt->format(DATE_ATOM)),
            'age_en_secondes' => match ($passage) {
                null => null,
                default => max(0, $fenetre->jusqua->getTimestamp() - $passage->finishedAt->getTimestamp()),
            },
            'controles' => $controles
                ->map(fn (StoredCheckResult $controle): array => $this->controle($controle, $texteBrut))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controle(StoredCheckResult $controle, bool $texteBrut): array
    {
        return [
            'nom' => Vocabulaire::forme(Vocabulaire::IDENTIFIANT, $controle->name),
            'libelle' => Vocabulaire::forme(Vocabulaire::LIBELLE, $controle->label),
            'statut' => Vocabulaire::parmi($controle->status, self::STATUTS),
            ...match ($texteBrut) {
                true => [
                    'non_fiable' => [
                        'resume' => new TexteNonFiable($controle->shortSummary, self::BUDGET_DU_RESUME),
                        'message' => new TexteNonFiable((string) $controle->notificationMessage, self::BUDGET_DU_MESSAGE),
                    ],
                ],
                false => [],
            },
        ];
    }
}
