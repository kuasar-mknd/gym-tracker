<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * La CI répartit tests/Browser en trois éclats par .github/scripts/dusk-eclats.php.
 * Un fichier oublié par la répartition ne tournerait plus nulle part, et un
 * fichier compté deux fois doublerait son coût : chaque fichier doit tomber
 * dans exactement un éclat, et la répartition doit être la même à chaque appel.
 */
/**
 * @return list<string>
 */
function eclatDusk(int $numero, int $eclats = 3): array
{
    $processus = new Process(['php', base_path('.github/scripts/dusk-eclats.php'), (string) $eclats, (string) $numero], base_path());
    $processus->mustRun();

    return array_values(array_filter(explode("\n", trim($processus->getOutput())), static fn (string $ligne): bool => $ligne !== ''));
}

it('répartit chaque fichier de tests/Browser dans exactement un éclat', function (): void {
    $fichiers = glob(base_path('tests/Browser/*Test.php'));
    $attendus = array_map(
        static fn (string $chemin): string => 'tests/Browser/'.basename($chemin),
        $fichiers === false ? [] : $fichiers
    );
    sort($attendus);

    $repartis = array_merge(eclatDusk(1), eclatDusk(2), eclatDusk(3));
    $tries = $repartis;
    sort($tries);

    expect($attendus)->not->toBe([])
        ->and($tries)->toBe($attendus)
        ->and(count($repartis))->toBe(count(array_unique($repartis)));
});

it('rend la même répartition à chaque appel', function (): void {
    expect(eclatDusk(2))->toBe(eclatDusk(2));
});
