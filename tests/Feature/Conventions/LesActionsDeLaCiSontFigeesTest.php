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
 * Les fichiers de `.github/workflows`, en `.yml` comme en `.yaml`.
 *
 * @return list<string>
 */
function cheminsDesWorkflows(): array
{
    $workflows = [];

    foreach (['yml', 'yaml'] as $extension) {
        $trouves = glob(base_path(".github/workflows/*.{$extension}"));
        $workflows = [...$workflows, ...($trouves === false ? [] : $trouves)];
    }

    return $workflows;
}

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

    foreach (cheminsDesWorkflows() as $chemin) {
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
 * suit la règle des images de `docker run` : une version exacte ou un digest,
 * jamais une étiquette flottante (`3`, `3.22`, `edge`) ni un nom de branche.
 */
function actionDeWorkflowEstFigee(string $action, ?string $commentaire): bool
{
    if (str_starts_with($action, './')) {
        return true;
    }

    if (str_starts_with($action, 'docker://')) {
        return imageDockerEstFigee(substr($action, strlen('docker://')));
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

/**
 * Vrai quand l'option de `docker run` prend sa valeur dans le mot suivant,
 * comme docker la lit. Une option longue booléenne (`--rm`, `--publish-all`,
 * `--oom-kill-disable`) ou qui porte sa valeur (`--name=web`) ne la prend
 * pas, pas plus que la fin des options (`--`). Une option courte se groupe
 * avec d'autres (`-dP`, `-ti`) : les lettres booléennes (`d`, `i`, `P`, `q`,
 * `t`) n'attendent rien, et la première lettre qui attend une valeur la prend
 * dans le reste du mot quand il en reste (`-p8080:80`, `-e=MODE=ci`), dans le
 * mot suivant sinon (`-p 8080:80`, `-dp 8080:80`). Une option inconnue est
 * tenue pour attendre sa valeur.
 */
function optionDeDockerRunAttendUneValeur(string $option): bool
{
    $longuesBooleennes = [
        '--detach', '--disable-content-trust', '--help', '--init', '--interactive', '--no-healthcheck',
        '--oom-kill-disable', '--privileged', '--publish-all', '--quiet', '--read-only', '--rm',
        '--sig-proxy', '--tty', '--use-api-socket',
    ];

    if (str_starts_with($option, '--')) {
        return $option !== '--' && ! str_contains($option, '=') && ! in_array($option, $longuesBooleennes, true);
    }

    $lettres = substr($option, 1);

    foreach (str_split($lettres) as $rang => $lettre) {
        if (! str_contains('dPqit', $lettre)) {
            return $rang === strlen($lettres) - 1;
        }
    }

    return false;
}

/**
 * Les images que lance chaque `docker run` (ou sa forme longue,
 * `docker container run`) d'un script d'étape : les lignes continuées par `\`
 * sont rejointes, les mots découpés comme le shell les découpe, les options
 * sautées comme docker les lit (`optionDeDockerRunAttendUneValeur`), et une
 * image passée par une variable (`"$image"`) est remplacée par la valeur que
 * le script lui donne. Une variable que le script ne pose pas reste telle
 * quelle, et n'est donc pas figée. `docker compose run` lance un service de
 * la composition, pas une image : il n'est pas lu.
 *
 * @return list<string>
 */
function imagesDesDockerRunDuScript(string $script): array
{
    $sansCommentaires = (string) preg_replace('/^\s*#.*$/m', '', $script);
    $joint = (string) preg_replace('/\\\\\r?\n/', ' ', $sansCommentaires);
    $images = [];

    preg_match_all('/\bdocker\s+(?:container\s+)?run\b(.*)$/m', $joint, $commandes);

    foreach ($commandes[1] as $suite) {
        preg_match_all('/(?:"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|[^\s"\'])+/', $suite, $jetons);
        $attendValeur = false;

        foreach ($jetons[0] as $jeton) {
            if ($attendValeur) {
                $attendValeur = false;

                continue;
            }

            if (str_starts_with($jeton, '-')) {
                $attendValeur = optionDeDockerRunAttendUneValeur($jeton);

                continue;
            }

            $image = trim($jeton, '"\'');

            if (preg_match('/^\$\{?(\w+)\}?$/', $image, $variable) === 1
                && preg_match_all('/(?:^|[\s;])'.$variable[1].'=("[^"]*"|\'[^\']*\'|\S+)/m', $joint, $affectations) > 0) {
                $image = trim((string) end($affectations[1]), '"\'');
            }

            $images[] = $image;

            break;
        }
    }

    return $images;
}

/**
 * Chaque image lancée par `docker run` dans une étape des workflows.
 *
 * @return list<array{ou: string, image: string}>
 */
function imagesLanceesParLesWorkflows(): array
{
    $images = [];

    foreach (cheminsDesWorkflows() as $chemin) {
        $workflow = Yaml::parseFile($chemin);
        $jobs = is_array($workflow) && is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

        foreach ($jobs as $job => $definition) {
            $etapes = is_array($definition) && is_array($definition['steps'] ?? null) ? $definition['steps'] : [];

            foreach ($etapes as $rang => $etape) {
                if (! is_array($etape) || ! is_string($etape['run'] ?? null)) {
                    continue;
                }

                $nom = is_string($etape['name'] ?? null) ? $etape['name'] : "étape {$rang}";

                foreach (imagesDesDockerRunDuScript($etape['run']) as $image) {
                    $images[] = ['ou' => basename($chemin)." > {$job} > {$nom}", 'image' => $image];
                }
            }
        }
    }

    return $images;
}

/**
 * Vrai quand l'image porte une version exacte (`1.179.0`, `v2.6.0`) ou un
 * digest, écrit ou calculé par le script (`@sha256:$digest`, l'image que le
 * job `build` vient de pousser). `latest`, une image sans étiquette et une
 * étiquette flottante (`v2`, `8.4`, `8-alpine`) suivent l'éditeur sans diff.
 */
function imageDockerEstFigee(string $image): bool
{
    return preg_match('/^[^\s@]+:v?\d+\.\d+\.\d+$/', $image) === 1
        || preg_match('/^[^\s@]+@sha256:(?:[0-9a-f]{64}|\$\{?\w+\}?)$/', $image) === 1;
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
    'une image à majeure flottante' => ['docker://alpine:3', null],
    'une image à mineure flottante' => ['docker://alpine:3.22', null],
    'une image qui suit une version de développement' => ['docker://alpine:edge', null],
    'une image au nom de branche' => ['docker://ghcr.io/owner/img:main', null],
]);

it('accepte une version, ou un commit complet qui nomme la sienne', function (string $action, ?string $commentaire): void {
    expect(actionDeWorkflowEstFigee($action, $commentaire))->toBeTrue();
})->with([
    'une majeure' => ['actions/checkout@v7', null],
    'une version exacte' => ['trufflesecurity/trufflehog@v3.97.9', null],
    'un commit et sa version' => ['trufflesecurity/trufflehog@4dd8831c5f12599465d4d45c3c447b4018a34c85', 'v3.97.9'],
    'une action d’un sous-dossier' => ['github/codeql-action/init@v4', null],
    'une action du dépôt' => ['./.github/actions/preparer', null],
    'une image à version exacte' => ['docker://alpine:3.22.1', null],
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

/*
 * Les images lancées par `docker run` dans une étape (Semgrep, OSV,
 * actionlint) ne sont lues par aucun écosystème de Dependabot : seule une
 * version exacte écrite dans le workflow les empêche de suivre l'éditeur sans
 * diff, comme TruffleHog avant #1990. Remplacer `semgrep/semgrep:1.179.0` par
 * `semgrep/semgrep:latest` ne faisait rougir aucune garde.
 */
it('lance chaque image de docker run à une version exacte', function (): void {
    $images = imagesLanceesParLesWorkflows();
    $mobiles = array_map(
        fn (array $lancee): string => "{$lancee['ou']} : {$lancee['image']}",
        array_values(array_filter($images, fn (array $lancee): bool => ! imageDockerEstFigee($lancee['image']))),
    );

    $noms = array_map(fn (array $lancee): string => (string) preg_replace('/[:@].*$/', '', $lancee['image']), $images);

    expect($noms)->toContain('semgrep/semgrep', 'rhysd/actionlint', 'ghcr.io/google/osv-scanner')
        ->and($mobiles)->toBe([], "Ces images de docker run suivent l'éditeur sans diff : leur donner une version exacte (X.Y.Z) ou un digest :\n- ".implode("\n- ", $mobiles));
});

it('lit l’image de chaque docker run comme le shell puis docker', function (string $script, array $images): void {
    expect(imagesDesDockerRunDuScript($script))->toBe($images);
})->with([
    'des options à valeur avant l’image' => ["docker run --rm -v \"\$PWD\":/src -w /src semgrep/semgrep:1.179.0 \\\n  semgrep scan --config p/default .", ['semgrep/semgrep:1.179.0']],
    'des arguments après l’image' => ['docker run --rm -v "$PWD":/repo:ro -w /repo rhysd/actionlint:1.7.12 -color', ['rhysd/actionlint:1.7.12']],
    'une image passée par une variable' => [
        "image=\"\$REGISTRY/\$IMAGE_NAME@sha256:\$digest\"\ndocker run -d --name app -p 8080:80 --add-host=host.docker.internal:host-gateway \\\n  -e APP_KEY=\"base64:\$(head -c 32 /dev/urandom | base64)\" \\\n  \"\$image\"",
        ['$REGISTRY/$IMAGE_NAME@sha256:$digest'],
    ],
    'une variable que le script ne pose pas' => ['docker run --rm "$IMAGE" scan', ['$IMAGE']],
    'un commentaire qui cite docker run' => ["# docker run alpine:latest\necho rien", []],
    'deux commandes' => ["docker run --rm a/b:1.2.3 x\ndocker run -it c/d", ['a/b:1.2.3', 'c/d']],
    'la forme longue docker container run' => ['docker container run --rm semgrep/semgrep:latest semgrep scan', ['semgrep/semgrep:latest']],
    'une option courte booléenne sans commande après l’image' => ['docker run -d -P nginx:latest', ['nginx:latest']],
    'une grappe d’options booléennes' => ['docker run --rm -ti alpine:3.22.1 sh', ['alpine:3.22.1']],
    'une grappe dont la dernière lettre attend sa valeur' => ['docker run -dP -dp 8080:80 nginx:1.27.3 nginx -g "daemon off;"', ['nginx:1.27.3']],
    'une valeur jointe à son option courte' => ['docker run -e=MODE=ci -p8080:80 alpine:3.22.1 sh', ['alpine:3.22.1']],
    'la fin des options' => ['docker run --rm -- alpine:3.22.1 sh', ['alpine:3.22.1']],
]);

/*
 * Une option booléenne que la garde croyait porteuse d'une valeur avalait
 * l'image : sans commande après elle, aucune image n'était lue, et une
 * étiquette flottante passait.
 */
it('ne prend jamais l’image pour la valeur d’une option booléenne', function (string $option): void {
    expect(imagesDesDockerRunDuScript("docker run {$option} nginx:latest"))->toBe(['nginx:latest']);
})->with([
    '-d', '-i', '-t', '-q', '-P', '-it', '-ti', '-td', '-dit', '-itd', '-dP',
    '--detach', '--disable-content-trust', '--help', '--init', '--interactive', '--no-healthcheck',
    '--oom-kill-disable', '--privileged', '--publish-all', '--quiet', '--read-only', '--rm',
    '--sig-proxy', '--sig-proxy=false', '--tty', '--use-api-socket',
]);

it('refuse une image de docker run qui suit l’éditeur', function (string $image): void {
    expect(imageDockerEstFigee($image))->toBeFalse();
})->with([
    'latest' => ['semgrep/semgrep:latest'],
    'sans étiquette' => ['rhysd/actionlint'],
    'une majeure flottante' => ['ghcr.io/google/osv-scanner:v2'],
    'une mineure flottante' => ['mysql:8.4'],
    'une variante flottante' => ['redis:8-alpine'],
    'une variable non résolue' => ['$IMAGE'],
    'un digest abrégé' => ['alpine@sha256:0123abcd'],
]);

it('accepte une image de docker run à version exacte ou à digest', function (string $image): void {
    expect(imageDockerEstFigee($image))->toBeTrue();
})->with([
    'une version' => ['semgrep/semgrep:1.179.0'],
    'une version préfixée' => ['ghcr.io/google/osv-scanner:v2.6.0'],
    'un digest écrit' => ['alpine@sha256:'.str_repeat('0123456789abcdef', 4)],
    'le digest que le build vient de pousser' => ['$REGISTRY/$IMAGE_NAME@sha256:$digest'],
]);
