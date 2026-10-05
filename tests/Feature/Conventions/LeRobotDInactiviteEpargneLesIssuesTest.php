<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le robot d'inactivité (`stale.yml`) fermait toute issue restée trente-cinq
 * jours sans activité, en « not planned » et sans exemption (#1987). Or les
 * issues tiennent lieu de feuille de route (.ai/rules/general.md) : une issue
 * de suivi peut attendre un mois une décision ou un accès au serveur, et une
 * fermeture par le robot passe pour une décision que personne n'a prise
 * (#1373 l'a été ainsi).
 *
 * Seules les PR vieillissent désormais, et le délai que leur message annonce
 * est celui que l'action applique.
 */

/**
 * Les réglages passés à actions/stale, tels que GitHub les lit.
 *
 * @return array<mixed>
 */
function robotDInactiviteReglages(): array
{
    $workflow = Yaml::parseFile(base_path('.github/workflows/stale.yml'));
    $etapes = data_get($workflow, 'jobs.stale.steps', []);

    foreach (is_array($etapes) ? $etapes : [] as $etape) {
        if (is_array($etape) && is_string($etape['uses'] ?? null) && str_starts_with($etape['uses'], 'actions/stale@')) {
            $reglages = $etape['with'] ?? [];

            return is_array($reglages) ? $reglages : [];
        }
    }

    throw new RuntimeException('stale.yml ne lance plus actions/stale : la garde ne sait plus quoi lire.');
}

it('ne marque ni ne ferme aucune issue', function (): void {
    $reglages = robotDInactiviteReglages();

    // -1 coupe l'étape pour les issues ; un réglage commun (days-before-stale, days-before-close) les toucherait de nouveau.
    expect($reglages['days-before-issue-stale'] ?? null)->toBe(-1)
        ->and($reglages['days-before-issue-close'] ?? null)->toBe(-1)
        ->and($reglages)->not->toHaveKey('days-before-stale')
        ->and($reglages)->not->toHaveKey('days-before-close');
});

it('ferme une PR inactive au délai que son message annonce', function (): void {
    $reglages = robotDInactiviteReglages();
    $delai = $reglages['days-before-pr-close'] ?? null;
    $message = $reglages['stale-pr-message'] ?? null;

    expect($delai)->toBeInt()->toBeGreaterThan(0)
        ->and($message)->toBeString();

    assert(is_int($delai) && is_string($message));

    expect($message)->toContain("in {$delai} days");
});
