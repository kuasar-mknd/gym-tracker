<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La passe nocturne part des heures après l'heure planifiée (#1989).
 *
 * Quatre textes disaient que la passe de mutation tournait à 03h17 UTC sur la
 * pointe de `main`, et en déduisaient qu'un tag posé après cette heure n'était
 * pas couvert : le README, les messages de la promotion de `ci.yml` et de
 * `release.yml`, et le commentaire de `mutation.yml`. GitHub ne garantit pas
 * l'heure d'un événement planifié : la passe est partie entre 07h42 et
 * 10h31 UTC du 1er septembre au 5 octobre 2026, et elle prend la pointe de
 * `main` au moment où elle part. Un tag posé avant ce lancement était couvert
 * par la passe du jour, et la consigne renvoyait pourtant vers une passe
 * manuelle de plus d'une heure, quand relancer la promotion suffisait.
 *
 * Chacun des quatre textes dit donc l'heure planifiée, telle que la règle le
 * `cron` de `mutation.yml`, et que GitHub la lance plusieurs heures plus tard
 * sur la pointe de `main` ; ceux qui guident un tag bloqué donnent les deux
 * façons de le débloquer : relancer la promotion après la passe du jour
 * (`gh run rerun`), ou lancer la passe sur le tag (`gh workflow run
 * mutation.yml --ref`).
 */

/**
 * L'heure planifiée de la passe, écrite comme les textes l'écrivent (`03h17`).
 */
function passeNocturneHeurePlanifiee(): string
{
    $workflow = Yaml::parseFile(base_path('.github/workflows/mutation.yml'));
    $cron = data_get($workflow, 'on.schedule.0.cron');

    if (! is_string($cron) || preg_match('/^(\d+)\s+(\d+)\s/', $cron, $champs) !== 1) {
        throw new RuntimeException('mutation.yml ne déclare plus de cron quotidien lisible : la garde ne sait plus quelle heure attendre.');
    }

    return sprintf('%02dh%02d', (int) $champs[2], (int) $champs[1]);
}

/**
 * Le script de l'étape qui exige la passe nocturne, dans le job `promotion` d'un workflow.
 */
function passeNocturneScriptDePromotion(string $workflow): string
{
    $etapes = data_get(Yaml::parseFile(base_path('.github/workflows/'.$workflow)), 'jobs.promotion.steps', []);
    $scripts = array_map(
        static fn (mixed $etape): string => is_array($etape) && is_string($etape['run'] ?? null) ? $etape['run'] : '',
        is_array($etapes) ? $etapes : [],
    );

    return implode("\n", $scripts);
}

/**
 * Les quatre textes, par endroit, sans accents graves ni retours à la ligne.
 *
 * @return array<string, string>
 */
function passeNocturneTextes(): array
{
    $readme = (string) file_get_contents(base_path('README.md'));
    $section = preg_match('/^## [^\n]*Mise en production\n(.*?)(?=^## )/msu', $readme, $trouvee) === 1 ? $trouvee[1] : '';

    $mutation = (string) file_get_contents(base_path('.github/workflows/mutation.yml'));
    $commentaire = preg_match('/^\s*schedule:\n((?:\s*#[^\n]*\n)+)\s*- cron:/m', $mutation, $trouve) === 1 ? $trouve[1] : '';

    $textes = [
        'README.md, « Mise en production »' => $section,
        'ci.yml, job promotion' => passeNocturneScriptDePromotion('ci.yml'),
        'release.yml, job promotion' => passeNocturneScriptDePromotion('release.yml'),
        'mutation.yml, commentaire du cron' => $commentaire,
    ];

    return array_map(
        static fn (string $texte): string => trim((string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace(['/`/', '/^\s*(?:#|echo\s+")/m', '/"\s*$/m'], '', $texte),
        )),
        $textes,
    );
}

it('trouve les quatre textes', function (): void {
    expect(array_filter(passeNocturneTextes(), static fn (string $texte): bool => $texte === ''))->toBe([]);
});

it('dit l heure planifiée et que GitHub lance la passe plus tard, sur la pointe de main', function (): void {
    $heure = passeNocturneHeurePlanifiee();
    $inexacts = [];

    foreach (passeNocturneTextes() as $endroit => $texte) {
        $manques = array_filter([
            "l'heure planifiée ({$heure})" => ! str_contains($texte, $heure),
            'le lancement « plusieurs heures plus tard »' => ! str_contains($texte, 'plusieurs heures plus tard'),
            'la pointe de main' => ! str_contains($texte, 'pointe de main'),
        ]);

        if ($manques !== []) {
            $inexacts[] = $endroit.' : manque '.implode(', ', array_keys($manques));
        }
    }

    expect($inexacts)->toBe([], implode("\n", [
        'Ces textes décrivent mal le lancement de la passe nocturne :',
        '  '.implode("\n  ", $inexacts),
        '',
        "GitHub lance la passe planifiée des heures après l'heure du cron, sur la pointe de main au moment où elle part (#1989).",
    ]));
});

it('donne les deux façons de débloquer un tag', function (): void {
    $incomplets = [];

    foreach (passeNocturneTextes() as $endroit => $texte) {
        if (str_starts_with($endroit, 'mutation.yml')) {
            continue;
        }

        $manques = array_filter([
            'relancer la promotion (gh run rerun)' => ! str_contains($texte, 'gh run rerun'),
            'lancer la passe sur le tag (gh workflow run mutation.yml --ref)' => ! str_contains($texte, 'gh workflow run mutation.yml --ref'),
        ]);

        if ($manques !== []) {
            $incomplets[] = $endroit.' : manque '.implode(', ', array_keys($manques));
        }
    }

    expect($incomplets)->toBe([], implode("\n", [
        'Ces textes ne donnent pas les deux façons de débloquer un tag :',
        '  '.implode("\n  ", $incomplets),
        '',
        'Un tag posé avant le lancement de la passe du jour est couvert par elle : relancer la promotion suffit. Sinon, lancer la passe sur le tag (#1989).',
    ]));
});
