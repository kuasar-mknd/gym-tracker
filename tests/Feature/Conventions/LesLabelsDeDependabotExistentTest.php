<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
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
 *
 * Le script lui-même tourne ici, contre un faux `yq` et un faux `gh`.
 * Dependabot ne garde que les labels dont le nom est exactement celui demandé,
 * casse comprise, alors que GitHub refuse de créer « php » à côté d'un « PHP » :
 * un label qui n'existe que sous une autre casse doit faire échouer le run, au
 * lieu de passer pour présent et de laisser revenir le défaut en silence.
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
 * Les scripts des étapes du workflow, tels que GitHub les exécute.
 *
 * @return list<string>
 */
function labelsDependabotScriptsBruts(): array
{
    $jobs = labelsDependabotWorkflow()['jobs'] ?? [];
    $scripts = [];

    foreach (is_array($jobs) ? $jobs : [] as $job) {
        $etapes = is_array($job) ? ($job['steps'] ?? []) : [];

        foreach (is_array($etapes) ? $etapes : [] as $etape) {
            if (is_array($etape) && is_string($etape['run'] ?? null)) {
                $scripts[] = $etape['run'];
            }
        }
    }

    return $scripts;
}

/**
 * Les scripts de toutes les étapes du workflow, lignes de continuation
 * rejointes, pour lire chaque commande d'un seul tenant.
 */
function labelsDependabotScripts(): string
{
    return (string) preg_replace('/\\\\\n\s*/', ' ', implode("\n", labelsDependabotScriptsBruts()));
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

/**
 * Exécute l'étape qui crée les labels comme GitHub Actions le fait (bash avec
 * `-eo pipefail`), contre un faux `yq` qui rend `$demandes` et un faux `gh`
 * qui connaît `$existants`. Comme l'API (422), le faux `gh` refuse de créer un
 * label qui existe déjà sous une casse ou une autre, ainsi que ceux de
 * `$refuses`.
 *
 * @param  list<string>  $demandes
 * @param  list<string>  $existants
 * @param  list<string>  $refuses
 * @return array{code: int|null, sortie: string, crees: list<string>, resume: string}
 */
function labelsDependabotExecuter(array $demandes, array $existants, array $refuses = []): array
{
    $script = collect(labelsDependabotScriptsBruts())
        ->first(fn (string $contenu): bool => str_contains($contenu, 'gh label create'));

    expect($script)->toBeString('aucune étape ne crée de label');
    assert(is_string($script));

    $dossier = storage_path('framework/testing/labels-dependabot-'.uniqid());
    File::ensureDirectoryExists($dossier);

    try {
        foreach (['demandes' => $demandes, 'existants' => $existants, 'refuses' => $refuses] as $fichier => $noms) {
            File::put($dossier.'/'.$fichier, $noms === [] ? '' : implode("\n", $noms)."\n");
        }

        File::put($dossier.'/crees', '');
        File::put($dossier.'/resume.md', '');
        File::put($dossier.'/etape.sh', $script);

        File::put($dossier.'/yq', <<<BASH
            #!/usr/bin/env bash
            cat '{$dossier}/demandes'
            BASH);

        File::put($dossier.'/gh', <<<BASH
            #!/usr/bin/env bash
            set -eu
            if [ "\$1" = api ]; then
                cat '{$dossier}/existants'
                exit 0
            fi
            if [ "\$1" = label ] && [ "\${2:-}" = create ]; then
                if grep -Fqxi -- "\$3" '{$dossier}/existants' || grep -Fqx -- "\$3" '{$dossier}/refuses'; then
                    printf 'HTTP 422: Validation Failed (already_exists)\\n' >&2
                    exit 1
                fi
                printf '%s\\n' "\$3" >> '{$dossier}/crees'
                exit 0
            fi
            printf 'gh factice : commande inattendue %s\\n' "\$*" >&2
            exit 2
            BASH);

        chmod($dossier.'/yq', 0755);
        chmod($dossier.'/gh', 0755);

        $processus = new Process(
            ['bash', '--noprofile', '--norc', '-eo', 'pipefail', $dossier.'/etape.sh'],
            base_path(),
            [
                'PATH' => $dossier.PATH_SEPARATOR.getenv('PATH'),
                'GH_TOKEN' => 'jeton-factice',
                'REPO' => 'kuasar-mknd/gym-tracker',
                'GITHUB_STEP_SUMMARY' => $dossier.'/resume.md',
            ],
        );
        $processus->run();

        $crees = array_values(array_filter(explode("\n", File::get($dossier.'/crees')), static fn (string $nom): bool => $nom !== ''));
        sort($crees);

        return [
            'code' => $processus->getExitCode(),
            'sortie' => $processus->getOutput().$processus->getErrorOutput(),
            'crees' => $crees,
            'resume' => File::get($dossier.'/resume.md'),
        ];
    } finally {
        File::deleteDirectory($dossier);
    }
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
    // Le chemin que labelsDependabotDemandes() parcourt : un autre ne rendrait rien, et le run ne créerait rien.
    expect(labelsDependabotScripts())->toContain("yq '.updates[].labels[]' .github/dependabot.yml");
});

it('crée sans --force, pour ne jamais réécrire un label choisi à la main', function (): void {
    preg_match_all('/gh label create[^\n]*/', labelsDependabotScripts(), $commandes);

    // `--force`, `--force=true`, `-f`, `-f=true`, ou `-f` groupé avec une autre option courte (`-fc 0366d6`).
    $forcees = array_values(array_filter(
        $commandes[0],
        fn (string $commande): bool => preg_match('/\s(--force|-f)([=A-Za-z]\S*)?(?=\s|$)/', $commande) === 1,
    ));

    expect($commandes[0])->not->toBeEmpty()
        ->and($forcees)->toBe([], "Ces créations réécriraient un label existant :\n- ".implode("\n- ", $forcees));
});

it('ne demande que le droit de créer un label', function (): void {
    $workflow = labelsDependabotWorkflow();
    $jobs = $workflow['jobs'] ?? [];
    $permissions = $workflow['permissions'] ?? null;

    // L'ordre des clefs ne change rien pour GitHub.
    if (is_array($permissions)) {
        ksort($permissions);
    }

    expect($permissions)->toBe(['contents' => 'read', 'issues' => 'write'])
        ->and(array_filter(
            is_array($jobs) ? $jobs : [],
            fn (mixed $job): bool => is_array($job) && array_key_exists('permissions', $job),
        ))->toBe([]);
});

it('crée les labels demandés qui manquent, et eux seuls', function (): void {
    $run = labelsDependabotExecuter(
        labelsDependabotDemandes(),
        ['bug', 'ci', 'dependencies', 'github-actions', 'javascript', 'npm', 'security'],
    );

    expect($run['code'])->toBe(0, $run['sortie'])
        ->and($run['crees'])->toBe(['composer', 'docker', 'php'])
        ->and($run['resume'])->toContain('`php`', '`composer`', '`docker`');
});

it('ne crée rien quand chaque label existe sous son nom exact', function (): void {
    $run = labelsDependabotExecuter(labelsDependabotDemandes(), [...labelsDependabotDemandes(), 'bug']);

    expect($run['code'])->toBe(0, $run['sortie'])
        ->and($run['crees'])->toBe([])
        ->and($run['resume'])->toContain('existent déjà');
});

it('échoue quand un label n’existe que sous une autre casse, que Dependabot ne poserait pas', function (): void {
    $run = labelsDependabotExecuter(
        labelsDependabotDemandes(),
        ['ci', 'dependencies', 'github-actions', 'javascript', 'npm', 'PHP'],
    );

    expect($run['code'])->not->toBe(0, 'le run passe vert alors que Dependabot omettra php')
        ->and($run['sortie'])->toContain('::error::', '« PHP »', '« php »')
        ->and($run['crees'])->toBe(['composer', 'docker'])
        ->and($run['resume'])->not->toContain('existent déjà')
        ->and($run['resume'])->toContain('`PHP`');
});

it('échoue quand la liste lue dans dependabot.yml est vide, au lieu de ne rien créer en silence', function (): void {
    $run = labelsDependabotExecuter([], ['dependencies']);

    expect($run['code'])->not->toBe(0, 'une expression yq qui ne lit plus rien passerait pour « rien à créer »')
        ->and($run['sortie'])->toContain('::error::')
        ->and($run['crees'])->toBe([]);
});

it('échoue quand GitHub refuse une création, au lieu de la taire', function (): void {
    $run = labelsDependabotExecuter(['dependencies', 'docker'], ['dependencies'], refuses: ['docker']);

    expect($run['code'])->not->toBe(0, 'un refus de GitHub est passé sous silence')
        ->and($run['crees'])->toBe([]);
});
