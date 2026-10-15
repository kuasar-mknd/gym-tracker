<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use App\Services\Diagnostic\ValeurLue;
use App\Services\Diagnostic\Vocabulaire;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * L'état d'Horizon tel que le diagnostic le rend : son statut, ses processus
 * et l'attente de chaque file. Lu dans les dépôts d'Horizon, jamais par son
 * API, qui porte des écritures et des charges utiles. Un cache injoignable
 * rend `illisible`, sans lever : le reste du diagnostic se lit quand même.
 */
final class EtatDeHorizon
{
    /**
     * @return array{statut: string, processus: int|null, files: array<int, array<string, mixed>>}
     */
    public function __invoke(): array
    {
        return rescue(
            fn (): array => $this->lire(),
            ['statut' => 'illisible', 'processus' => null, 'files' => []],
            report: false,
        );
    }

    /**
     * @return array{statut: string, processus: int, files: array<int, array<string, mixed>>}
     */
    private function lire(): array
    {
        $maitres = collect(app(MasterSupervisorRepository::class)->all());
        $files = collect(app(WorkloadRepository::class)->get())
            ->map(static fn (mixed $file): array => [
                'nom' => Vocabulaire::forme(Vocabulaire::IDENTIFIANT, JsonLu::champ($file, 'name')),
                'taille' => ValeurLue::entier(JsonLu::champ($file, 'length')),
                'attente_en_secondes' => ValeurLue::entier(JsonLu::champ($file, 'wait')),
                'processus' => ValeurLue::entier(JsonLu::champ($file, 'processes')),
            ])
            ->values();

        return [
            'statut' => match (true) {
                $maitres->isEmpty() => 'arrete',
                $maitres->contains(static fn (mixed $maitre): bool => is_object($maitre) && ($maitre->status ?? null) === 'paused') => 'en_pause',
                default => 'actif',
            },
            'processus' => array_sum(array_map(static fn (array $file): int => $file['processus'] ?? 0, $files->all())),
            'files' => $files->all(),
        ];
    }
}
