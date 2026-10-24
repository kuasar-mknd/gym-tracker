<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * `main-failure-issue.yml` ouvre une issue quand la CI ou la passe nocturne
 * ne passe pas sur main, parce que la promotion d'un tag exige l'une et l'autre
 * vertes sur son commit. Il ne regardait que les runs en échec. Or un job qui
 * dépasse son `timeout-minutes` est annulé, pas en échec : du 16 au 24/10, la
 * part App\Services de la passe nocturne a été annulée à sa limite chaque nuit,
 * aucune issue ne s'est ouverte, et le blocage de la livraison ne s'est vu que
 * le jour de la release.
 *
 * Cette garde exige qu'un run annulé ouvre l'issue comme un run en échec, que
 * les jobs annulés y soient nommés, et que l'issue dise qu'il a été annulé.
 */

/**
 * Le job qui ouvre l'issue, tel que GitHub le lit.
 *
 * @return array<mixed>
 */
function signalementDuRougeLeJob(): array
{
    $job = data_get(Yaml::parseFile(base_path('.github/workflows/main-failure-issue.yml')), 'jobs.signaler');

    if (! is_array($job)) {
        throw new RuntimeException('main-failure-issue.yml ne déclare plus le job « signaler » : la garde ne sait plus quoi lire.');
    }

    return $job;
}

/**
 * Le script de l'étape qui ouvre ou complète l'issue.
 */
function signalementDuRougeLeScript(): string
{
    foreach ((array) (signalementDuRougeLeJob()['steps'] ?? []) as $etape) {
        if (is_array($etape) && is_string($etape['run'] ?? null) && str_contains($etape['run'], 'gh issue create')) {
            return $etape['run'];
        }
    }

    throw new RuntimeException('main-failure-issue.yml n’a plus d’étape qui ouvre l’issue : la garde ne sait plus quoi lire.');
}

it('se lance pour un run annulé comme pour un run en échec', function (): void {
    $condition = signalementDuRougeLeJob()['if'] ?? null;

    expect($condition)->toBeString();

    assert(is_string($condition));

    expect($condition)->toContain("github.event.workflow_run.conclusion == 'failure'")
        ->and($condition)->toContain("github.event.workflow_run.conclusion == 'cancelled'")
        ->and($condition)->not->toContain('&&');
});

it('nomme les jobs annulés avec ceux en échec', function (): void {
    expect(signalementDuRougeLeScript())
        ->toContain('select(.conclusion == "failure" or .conclusion == "cancelled")')
        ->toContain('(annule)');
});

it('dit dans l’issue que le run a été annulé', function (): void {
    $etape = collect((array) (signalementDuRougeLeJob()['steps'] ?? []))
        ->first(static fn (mixed $etape): bool => is_array($etape) && is_string($etape['run'] ?? null) && str_contains($etape['run'], 'gh issue create'));

    expect(data_get($etape, 'env.CONCLUSION'))->toBe('${{ github.event.workflow_run.conclusion }}')
        ->and(signalementDuRougeLeScript())
        ->toContain('if [ "$CONCLUSION" = "cancelled" ]; then')
        ->toContain('a ete annule sur');
});
