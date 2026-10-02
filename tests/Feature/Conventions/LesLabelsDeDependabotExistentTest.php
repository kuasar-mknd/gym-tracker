<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Dependabot pose sur ses PR les labels que `.github/dependabot.yml` lui
 * demande, mais seulement ceux qui existent sur le dépôt : il n'en crée aucun.
 * `php`, `composer` et `docker` manquaient (#1905). Chaque PR Composer arrivait
 * donc sans eux, avec un commentaire « The following labels could not be
 * found » que personne ne lisait, et le filtrage par écosystème que la
 * configuration promettait ne marchait pas.
 *
 * Plutôt que de créer trois labels à la main, une fois, le workflow
 * `labels-dependabot.yml` crée ce qui manque chaque fois que la liste change.
 * Aucun test ne voit les labels du dépôt sans réseau ; ces gardes tiennent ce
 * qui rend le défaut impossible : le workflow se déclenche quand
 * `dependabot.yml` change — et quand il change lui-même, sans quoi la fusion
 * qui l'ajoute ne créerait rien —, il lit la liste dans le fichier au lieu de
 * la recopier, et il crée sans `--force`, qui écraserait la couleur et la
 * description d'un label choisi à la main.
 */

/**
 * Le workflow qui crée les labels manquants, tel que GitHub le lit.
 *
 * @return array<mixed>
 */
function labelsDependabotWorkflow(): array
{
    $chemin = base_path('.github/workflows/labels-dependabot.yml');

    expect($chemin)->toBeFile();

    $workflow = Yaml::parseFile($chemin);

    return is_array($workflow) ? $workflow : [];
}

/**
 * Les scripts de toutes les étapes du workflow, lignes de continuation
 * rejointes, pour lire chaque commande d'un seul tenant.
 */
function labelsDependabotScripts(): string
{
    $jobs = labelsDependabotWorkflow()['jobs'] ?? [];
    $scripts = '';

    foreach (is_array($jobs) ? $jobs : [] as $job) {
        $etapes = is_array($job) ? ($job['steps'] ?? []) : [];

        foreach (is_array($etapes) ? $etapes : [] as $etape) {
            if (is_array($etape) && is_string($etape['run'] ?? null)) {
                $scripts .= $etape['run']."\n";
            }
        }
    }

    return (string) preg_replace('/\\\\\n\s*/', ' ', $scripts);
}

/**
 * Les labels que `.github/dependabot.yml` demande, tous écosystèmes confondus.
 *
 * @return list<string>
 */
function labelsDependabotDemandes(): array
{
    $configuration = Yaml::parseFile(base_path('.github/dependabot.yml'));
    $mises = is_array($configuration) ? ($configuration['updates'] ?? []) : [];
    $labels = [];

    foreach (is_array($mises) ? $mises : [] as $mise) {
        $demandes = is_array($mise) ? ($mise['labels'] ?? []) : [];

        foreach (is_array($demandes) ? $demandes : [] as $label) {
            if (is_string($label)) {
                $labels[] = $label;
            }
        }
    }

    return array_values(array_unique($labels));
}

it('crée les labels de dependabot.yml dès que le fichier ou le workflow change sur main', function (): void {
    $workflow = labelsDependabotWorkflow();

    expect(labelsDependabotDemandes())->toContain('dependencies', 'php', 'composer', 'docker')
        ->and(data_get($workflow, 'on'))->toBeArray()->toHaveKeys(['push', 'workflow_dispatch'])
        ->and(data_get($workflow, 'on.push.branches'))->toBe(['main'])
        ->and(data_get($workflow, 'on.push.paths'))->toBeArray()->toContain(
            '.github/dependabot.yml',
            '.github/workflows/labels-dependabot.yml',
        );
});

it('lit les labels dans dependabot.yml au lieu de les recopier', function (): void {
    expect(labelsDependabotScripts())->toContain('.github/dependabot.yml', '.labels');
});

it('crée sans --force, pour ne jamais réécrire un label choisi à la main', function (): void {
    preg_match_all('/gh label create[^\n]*/', labelsDependabotScripts(), $commandes);

    $forcees = array_values(array_filter(
        $commandes[0],
        fn (string $commande): bool => preg_match('/\s(--force|-f)(\s|$)/', $commande) === 1,
    ));

    expect($commandes[0])->not->toBeEmpty()
        ->and($forcees)->toBe([], "Ces créations réécriraient un label existant :\n- ".implode("\n- ", $forcees));
});

it('ne demande que le droit de créer un label', function (): void {
    $workflow = labelsDependabotWorkflow();
    $jobs = $workflow['jobs'] ?? [];

    expect($workflow['permissions'] ?? null)->toBe(['contents' => 'read', 'issues' => 'write'])
        ->and(array_filter(
            is_array($jobs) ? $jobs : [],
            fn (mixed $job): bool => is_array($job) && array_key_exists('permissions', $job),
        ))->toBe([]);
});
