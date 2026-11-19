<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Le README présentait sept variables « propres au déploiement » (#1906).
 * Deux d'entre elles, HORIZON_ALLOWED_EMAILS et ADMIN_INITIAL_PASSWORD,
 * n'atteignaient aucun conteneur : posées dans la pile, elles ne faisaient
 * rien, et Horizon restait fermé à tous. À l'inverse, APP_KEY, les DB_*,
 * REDIS_PASSWORD, les MAIL_* ou DB_ROOT_PASSWORD, sans lesquelles la pile ne
 * démarre pas ou n'envoie rien, n'y figuraient pas.
 *
 * Une variable se documente là où on la cherche, et la documentation ne dit
 * que ce qui est vrai : ces gardes refusent une variable que la production,
 * le gabarit ou la CI lisent sans que la référence des variables
 * (`docs/variables-d-environnement.md`) la nomme entre accents graves, et une
 * variable présentée comme réglable en production que `docker-compose.prod.yml`
 * ne transmet pas.
 */

function documentationContenu(): string
{
    return (string) file_get_contents(base_path('docs/variables-d-environnement.md'));
}

/**
 * Les noms de variables cités entre accents graves, seuls ou avec leur valeur.
 *
 * @return list<string>
 */
function documentationVariablesCiteesDans(string $texte): array
{
    preg_match_all('/`([A-Z][A-Z0-9_]*)(?:=[^`]*)?`/', $texte, $correspondances);

    return array_values(array_unique($correspondances[1]));
}

/**
 * Les variables qu'interpole `docker-compose.prod.yml`, c'est-à-dire celles
 * qu'on pose dans l'environnement de la pile.
 *
 * @return list<string>
 */
function documentationVariablesInterpoleesEnProduction(): array
{
    $composition = (string) file_get_contents(base_path('docker-compose.prod.yml'));

    preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', $composition, $correspondances);

    return array_values(array_unique($correspondances[1]));
}

/**
 * Les variables que reçoivent les conteneurs de l'application, valeurs fixées
 * par la composition comprises.
 *
 * @return list<string>
 */
function documentationVariablesDesConteneursDeLApplication(): array
{
    $composition = Yaml::parseFile(base_path('docker-compose.prod.yml'));
    $services = is_array($composition) ? ($composition['services'] ?? []) : [];
    $variables = [];

    foreach (is_array($services) ? $services : [] as $service) {
        if (! is_array($service) || ! is_string($service['image'] ?? null) || ! str_starts_with($service['image'], 'ghcr.io/kuasar-mknd/gym-tracker')) {
            continue;
        }

        $environnement = $service['environment'] ?? [];

        foreach (array_keys(is_array($environnement) ? $environnement : []) as $nom) {
            $variables[] = (string) $nom;
        }
    }

    return array_values(array_unique($variables));
}

/**
 * Les variables que pose l'image elle-même, par les `ENV` du `Dockerfile`.
 *
 * @return list<string>
 */
function documentationVariablesDeLImage(): array
{
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    preg_match_all('/^ENV\s+([A-Z][A-Z0-9_]*)[=\s]/m', $dockerfile, $correspondances);

    return array_values(array_unique($correspondances[1]));
}

/**
 * Les variables déclarées dans `.env.example`, lignes commentées comprises.
 *
 * @return list<string>
 */
function documentationVariablesDuGabarit(): array
{
    $gabarit = (string) file_get_contents(base_path('.env.example'));

    preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/m', $gabarit, $correspondances);

    return array_values(array_unique($correspondances[1]));
}

/**
 * Les secrets de dépôt que lisent les workflows.
 *
 * @return list<string>
 */
function documentationSecretsDeLaCi(): array
{
    $secrets = [];
    $workflows = glob(base_path('.github/workflows/*.yml'));

    foreach ($workflows === false ? [] : $workflows as $workflow) {
        preg_match_all('/secrets\.([A-Za-z_][A-Za-z0-9_]*)/', (string) file_get_contents($workflow), $correspondances);
        $secrets = [...$secrets, ...$correspondances[1]];
    }

    return array_values(array_unique($secrets));
}

/**
 * Les variables de la première colonne du tableau « Production » de la référence :
 * celles que le lecteur est invité à poser dans la pile.
 *
 * @return list<string>
 */
function documentationVariablesReglablesEnProduction(): array
{
    if (preg_match('/^## Production\b.*?(?=^#{1,2} )/ms', documentationContenu(), $section) !== 1) {
        return [];
    }

    $variables = [];

    foreach (explode("\n", $section[0]) as $ligne) {
        if (! str_starts_with($ligne, '| `')) {
            continue;
        }

        $variables = [...$variables, ...documentationVariablesCiteesDans(explode('|', $ligne)[1])];
    }

    return array_values(array_unique($variables));
}

/**
 * @param  list<string>  $variables
 * @return list<string>
 */
function documentationVariablesAbsentesDuReadme(array $variables): array
{
    return array_values(array_diff($variables, documentationVariablesCiteesDans(documentationContenu())));
}

it('nomme dans la référence des variables chaque variable que la production lit', function (): void {
    $variables = array_values(array_unique([
        ...documentationVariablesInterpoleesEnProduction(),
        ...documentationVariablesDesConteneursDeLApplication(),
    ]));

    expect($variables)->toContain('APP_KEY', 'DB_ROOT_PASSWORD', 'BACKUP_ARCHIVE_PASSWORD', 'OCTANE_SERVER')
        ->and(documentationVariablesAbsentesDuReadme($variables))->toBe([], sprintf(
            "docker-compose.prod.yml transmet ces variables, que docs/variables-d-environnement.md ne nomme pas :\n- %s",
            implode("\n- ", documentationVariablesAbsentesDuReadme($variables)),
        ));
});

it('nomme dans la référence des variables chaque variable que pose l’image', function (): void {
    $variables = documentationVariablesDeLImage();

    expect($variables)->toContain('APP_ENV', 'APP_DEBUG')
        ->and(documentationVariablesAbsentesDuReadme($variables))->toBe([], sprintf(
            "Le Dockerfile pose ces variables dans l'image, que docs/variables-d-environnement.md ne nomme pas :\n- %s",
            implode("\n- ", documentationVariablesAbsentesDuReadme($variables)),
        ));
});

it('nomme dans la référence des variables chaque variable du gabarit .env.example', function (): void {
    $variables = documentationVariablesDuGabarit();

    expect($variables)->toContain('APP_KEY', 'DB_PASSWORD', 'VAPID_PUBLIC_KEY')
        ->and(documentationVariablesAbsentesDuReadme($variables))->toBe([], sprintf(
            "Le gabarit .env.example déclare ces variables, que docs/variables-d-environnement.md ne nomme pas :\n- %s",
            implode("\n- ", documentationVariablesAbsentesDuReadme($variables)),
        ));
});

it('nomme dans la référence des variables chaque secret que la CI lit', function (): void {
    $secrets = documentationSecretsDeLaCi();

    expect($secrets)->toContain('GITHUB_TOKEN')
        ->and(documentationVariablesAbsentesDuReadme($secrets))->toBe([], sprintf(
            "Les workflows lisent ces secrets de dépôt, que docs/variables-d-environnement.md ne nomme pas :\n- %s",
            implode("\n- ", documentationVariablesAbsentesDuReadme($secrets)),
        ));
});

it('ne présente comme réglable en production que ce que la composition transmet', function (): void {
    $reglables = documentationVariablesReglablesEnProduction();
    $intransmises = array_values(array_diff($reglables, documentationVariablesInterpoleesEnProduction()));

    expect($reglables)->toContain('APP_KEY', 'HORIZON_ALLOWED_EMAILS')
        ->and($intransmises)->toBe([], sprintf(
            'Le tableau « Production » de docs/variables-d-environnement.md invite à poser ces variables dans la pile, mais docker-compose.prod.yml '
            ."ne les transmet à aucun conteneur : posées, elles ne feraient rien.\n- %s",
            implode("\n- ", $intransmises),
        ));
});

it('ne présente dans la procédure du README que des variables que la composition transmet', function (): void {
    $readme = (string) file_get_contents(base_path('README.md'));
    $variables = array_values(array_filter(
        documentationVariablesCiteesDans($readme),
        static fn (string $nom): bool => str_contains($nom, '_'),
    ));
    $intransmises = array_values(array_diff($variables, documentationVariablesInterpoleesEnProduction()));

    expect($variables)->toContain('APP_KEY', 'DB_ROOT_PASSWORD', 'BACKUP_HOST_PATH')
        ->and($intransmises)->toBe([], sprintf(
            "Le README invite à poser ces variables dans la pile, mais docker-compose.prod.yml ne les transmet pas :\n- %s",
            implode("\n- ", $intransmises),
        ));
});
