<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le contrôle `secrets`, que la release exige, lançait
 * `trufflesecurity/trufflehog@main`, dont l'action.yml tire par défaut l'image
 * `latest` (#1990). Le code qui rendait le verdict changeait donc à chaque
 * commit et à chaque image de l'éditeur, sans aucun diff dans ce dépôt, et
 * Dependabot ne propose aucune montée pour une référence de branche : rien de
 * tout cela n'était relu.
 *
 * Une action se référence désormais par une version (`@v7`, `@v3.97.9`), ou
 * par un commit complet suivi de sa version en commentaire, que Dependabot sait
 * monter. L'image de TruffleHog est une entrée de l'action, que Dependabot ne
 * lit pas : elle doit porter la version de l'action, pour qu'une PR qui monte
 * l'une sans l'autre rougisse au lieu de passer.
 */

/**
 * Chaque `uses:` des workflows, tel qu'il est écrit, avec le commentaire qui le
 * suit : Symfony Yaml jette les commentaires, et c'est là que se lit la version
 * d'une action épinglée par commit.
 *
 * @return list<array{fichier: string, ligne: int, action: string, commentaire: string|null}>
 */
function actionsDesWorkflowsReferencees(): array
{
    $references = [];
    $workflows = [];

    foreach (['yml', 'yaml'] as $extension) {
        $trouves = glob(base_path(".github/workflows/*.{$extension}"));
        $workflows = [...$workflows, ...($trouves === false ? [] : $trouves)];
    }

    foreach ($workflows as $chemin) {
        $lignes = file($chemin);

        foreach ($lignes === false ? [] : $lignes as $index => $ligne) {
            if (preg_match('/^\s*(?:-\s+)?uses:\s*[\'"]?([^\s\'"#]+)[\'"]?(?:\s+#\s*(\S+))?/', $ligne, $morceaux) === 1) {
                $references[] = [
                    'fichier' => basename($chemin),
                    'ligne' => $index + 1,
                    'action' => $morceaux[1],
                    'commentaire' => $morceaux[2] ?? null,
                ];
            }
        }
    }

    return $references;
}

/**
 * Vrai quand la référence désigne une version figée : une étiquette de version
 * (`v7`, `v3.97.9`), ou un commit complet que son commentaire nomme
 * (`@4dd8…85 # v3.97.9`). Une branche (`main`, `master`, `develop`,
 * `release/v3`), un commit abrégé ou un commit sans sa version ne le sont pas.
 * Une action du dépôt (`./…`) n'a pas de référence ; une image (`docker://…`)
 * doit porter une étiquette autre que `latest`.
 */
function actionDeWorkflowEstFigee(string $action, ?string $commentaire): bool
{
    if (str_starts_with($action, './')) {
        return true;
    }

    if (str_starts_with($action, 'docker://')) {
        return preg_match('/^docker:\/\/[^:@\s]+(?::(?!latest$)[\w.-]+|@sha256:[0-9a-f]{64})$/', $action) === 1;
    }

    if (preg_match('/^[\w.-]+\/[\w.\/-]+@(.+)$/', $action, $morceaux) !== 1) {
        return false;
    }

    if (preg_match('/^v\d+(?:\.\d+){0,2}$/', $morceaux[1]) === 1) {
        return true;
    }

    return preg_match('/^[0-9a-f]{40}$/', $morceaux[1]) === 1
        && $commentaire !== null
        && preg_match('/^v?\d+(?:\.\d+){0,2}$/', $commentaire) === 1;
}

/**
 * L'étape TruffleHog du job `secrets`, telle que GitHub la lit, et la
 * référence brute de son `uses:`.
 *
 * @return array{etape: array<mixed>, reference: array{fichier: string, ligne: int, action: string, commentaire: string|null}}
 */
function actionTruffleHogDeLaCi(): array
{
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $etapes = data_get($workflow, 'jobs.secrets.steps', []);
    $etape = collect(is_array($etapes) ? $etapes : [])->first(
        fn (mixed $candidate): bool => is_array($candidate)
            && is_string($candidate['uses'] ?? null)
            && str_starts_with($candidate['uses'], 'trufflesecurity/trufflehog@'),
    );

    if (! is_array($etape)) {
        throw new RuntimeException('Le job secrets ne lance plus TruffleHog : la garde ne sait plus quoi lire.');
    }

    $reference = collect(actionsDesWorkflowsReferencees())->first(
        fn (array $candidate): bool => $candidate['fichier'] === 'ci.yml' && $candidate['action'] === $etape['uses'],
    );

    if (! is_array($reference)) {
        throw new RuntimeException('Le uses: de TruffleHog est introuvable dans le texte de ci.yml.');
    }

    return ['etape' => $etape, 'reference' => $reference];
}

it('référence chaque action des workflows par une version, jamais par une branche', function (): void {
    $references = actionsDesWorkflowsReferencees();

    $mobiles = array_map(
        fn (array $reference): string => "{$reference['fichier']}:{$reference['ligne']} {$reference['action']}",
        array_values(array_filter(
            $references,
            fn (array $reference): bool => ! actionDeWorkflowEstFigee($reference['action'], $reference['commentaire']),
        )),
    );

    expect($references)->not->toBeEmpty('aucun uses: lu dans .github/workflows : la garde ne voit plus rien')
        ->and($mobiles)->toBe([], "Ces actions suivent une branche ou une version qu'aucun diff ne montre :\n- ".implode("\n- ", $mobiles));
});

it('refuse une référence qui suit une branche ou ne nomme pas sa version', function (string $action, ?string $commentaire): void {
    expect(actionDeWorkflowEstFigee($action, $commentaire))->toBeFalse();
})->with([
    'la branche main' => ['trufflesecurity/trufflehog@main', null],
    'la branche master' => ['actions/checkout@master', null],
    'une branche quelconque' => ['owner/action@develop', null],
    'une branche à barre oblique' => ['owner/action@release/v3', null],
    'une branche malgré un commentaire de version' => ['trufflesecurity/trufflehog@main', 'v3.97.9'],
    'un commit abrégé' => ['trufflesecurity/trufflehog@4dd8831', 'v3.97.9'],
    'un commit sans sa version' => ['trufflesecurity/trufflehog@4dd8831c5f12599465d4d45c3c447b4018a34c85', null],
    'une action sans référence' => ['actions/checkout', null],
    'une image latest' => ['docker://alpine:latest', null],
    'une image sans étiquette' => ['docker://alpine', null],
]);

it('accepte une version, ou un commit complet qui nomme la sienne', function (string $action, ?string $commentaire): void {
    expect(actionDeWorkflowEstFigee($action, $commentaire))->toBeTrue();
})->with([
    'une majeure' => ['actions/checkout@v7', null],
    'une version exacte' => ['trufflesecurity/trufflehog@v3.97.9', null],
    'un commit et sa version' => ['trufflesecurity/trufflehog@4dd8831c5f12599465d4d45c3c447b4018a34c85', 'v3.97.9'],
    'une action d’un sous-dossier' => ['github/codeql-action/init@v4', null],
    'une action du dépôt' => ['./.github/actions/preparer', null],
    'une image étiquetée' => ['docker://alpine:3.22', null],
]);

it('lance l’image de TruffleHog à la version de son action', function (): void {
    ['etape' => $etape, 'reference' => $reference] = actionTruffleHogDeLaCi();
    $version = data_get($etape, 'with.version');
    $versionDeLAction = preg_match('/@v(\d+\.\d+\.\d+)$/', $reference['action'], $etiquette) === 1
        ? $etiquette[1]
        : ltrim((string) $reference['commentaire'], 'v');

    // Sans `version`, l'action lance l'image `latest` ; une autre image que celle de l'éditeur ne suivrait plus aucune version.
    expect($version)->toBeString('TruffleHog lance l’image latest : passer `version` à la version de l’action')
        ->and($version)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($versionDeLAction)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($version)->toBe($versionDeLAction, 'l’image de TruffleHog et son action ont des versions différentes : monter `version` avec l’action')
        ->and($etape['with'] ?? [])->not->toHaveKey('image');
});

it('laisse Dependabot proposer la montée des actions des workflows', function (): void {
    $configuration = Yaml::parseFile(base_path('.github/dependabot.yml'));
    $mises = is_array($configuration) ? ($configuration['updates'] ?? []) : [];

    $actions = array_values(array_filter(
        is_array($mises) ? $mises : [],
        fn (mixed $mise): bool => is_array($mise)
            && ($mise['package-ecosystem'] ?? null) === 'github-actions'
            && ($mise['directory'] ?? null) === '/',
    ));

    expect($actions)->toHaveCount(1)
        ->and(data_get($actions, '0.schedule.interval'))->toBeString()
        ->and(data_get($actions, '0.open-pull-requests-limit'))->not->toBe(0);
});

it('cite dans la règle des dépendances l’image OSV que la CI lance', function (): void {
    $motif = '/ghcr\.io\/google\/osv-scanner:(v[\d.]+)/';

    preg_match_all($motif, (string) file_get_contents(base_path('.github/workflows/ci.yml')), $dansLaCi);
    preg_match_all($motif, (string) file_get_contents(base_path('.ai/rules/dependencies.md')), $dansLaRegle);

    expect(array_unique($dansLaCi[1]))->toHaveCount(1)
        ->and(array_unique($dansLaRegle[1]))->toBe(array_unique($dansLaCi[1]));
});
