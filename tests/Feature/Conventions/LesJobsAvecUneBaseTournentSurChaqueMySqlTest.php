<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La montée vers MySQL 9.7 commence par la CI : chaque job des workflows qui a
 * une base tourne sur la version de la production et sur la LTS visée, avant
 * que la pile ne bascule dans une release à elle (`.ai/rules/dependencies.md`).
 * La version de la production se lit dans `docker-compose.prod.yml` ; la LTS
 * visée s'écrit à sa version exacte, que seule une PR monte (ni Dependabot ni
 * la garde des images de `docker run` ne lisent une image de service). Sous
 * 9.7, le schéma figé ne se chargeait pas (une colonne générée de Pulse
 * appelait `md5()`), et aucun job ne le voyait. Une fois la bascule faite, la
 * production a rejoint la LTS visée et la matrice se réduit à elle : 8.4 en
 * sort, puisqu'aucun serveur 8.4 ne relit un volume mis à niveau.
 *
 * Une base se reconnaît à son image (`mysql`, quel que soit le nom du service
 * ou s'il s'agit du conteneur du job), dans chaque fichier de
 * `.github/workflows` ; la passe nocturne seule reste sur la version de la
 * production. Une base lancée par `docker run` dans un script n'est pas lue :
 * elle se prend en service.
 *
 * Une version écrite dans la liste doit aussi tourner et faire tomber son job :
 * ni `exclude` ni `include` ne la nomment, le job ne se tolère pas en échec, la
 * version ne se lit que dans le nom du job, de ses étapes et de ses artefacts
 * et dans l'image de sa base (une variable d'environnement, une condition ou
 * un script qui la lisent pourraient la sauter), et l'étape qui teste ne porte
 * ni condition ni `continue-on-error`.
 *
 * Une matrice renomme le contrôle de son job (`laravel-tests (MySQL 8.4)`) :
 * le contrôle `laravel-tests` qu'attendent la protection de branche et le job
 * `build` reste donc un verdict qui attend toutes les versions, comme
 * `browser-tests` pour les éclats. Chacun tombe dès que son job n'a pas réussi.
 */

/**
 * La LTS vers laquelle la pile monte. Tant que la production est plus
 * ancienne, la matrice la teste à sa version exacte ; la PR de la bascule n'y
 * touche pas. La PR qui mettra en CI la LTS d'après montera cette valeur.
 */
function mysqlMatriceLtsVisee(): string
{
    return '9.7';
}

/**
 * La version de MySQL de la production : l'étiquette de l'image du service
 * `db` de `docker-compose.prod.yml`.
 */
function mysqlMatriceVersionDeLaProduction(): string
{
    $image = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services.db.image');

    expect($image)->toBeString()->toStartWith('mysql:');
    assert(is_string($image));

    return substr($image, strlen('mysql:'));
}

/**
 * Chaque fichier de `.github/workflows`, en `.yml` comme en `.yaml`, lu, par
 * nom de fichier.
 *
 * @return array<string, array<mixed>>
 */
function mysqlMatriceWorkflows(): array
{
    $workflows = [];

    foreach (['yml', 'yaml'] as $extension) {
        $chemins = glob(base_path(".github/workflows/*.{$extension}"));

        foreach ($chemins === false ? [] : $chemins as $chemin) {
            $workflows[basename($chemin)] = mysqlMatriceTableau(Yaml::parseFile($chemin));
        }
    }

    ksort($workflows);

    return $workflows;
}

/**
 * Les workflows dont les bases suivent la seule version de la production : la
 * passe nocturne. Chaque autre workflow porte la matrice.
 *
 * @return list<string>
 */
function mysqlMatriceWorkflowsSurLaProduction(): array
{
    return ['mutation.yml'];
}

/**
 * Vrai quand cette expression de GitHub lit la version de MySQL de la matrice
 * (`matrix.mysql`, `matrix['mysql']`, la matrice entière) ou le rang du job
 * dans la matrice (`strategy.job-index`), qui la désigne aussi. Une autre clé
 * de la matrice (`matrix.shard`), `strategy.fail-fast` et le contenu d'une
 * chaîne ne comptent pas.
 */
function mysqlMatriceExpressionLitLaVersion(string $expression): bool
{
    $sansCeQuiNeLaLitPas = preg_replace(
        [
            '/\bmatrix\s*(?:\.\s*(?!mysql\b)[a-z_][\w-]*|\[\s*\'(?!mysql\')[\w-]+\'\s*\])/i',
            '/\bstrategy\s*\.\s*(?:fail-fast|max-parallel)\b/i',
            "/'(?:[^']|'')*'/",
        ],
        '',
        $expression,
    );

    return preg_match('/\b(?:matrix|strategy)\b/i', (string) $sansCeQuiNeLaLitPas) === 1;
}

/**
 * Vrai quand cette valeur d'un workflow lit la version de MySQL : dans ses
 * expressions `${{ … }}`, ou dans tout son texte pour une condition (`if:`),
 * qui est une expression même sans `${{ }}`.
 */
function mysqlMatriceTexteLitLaVersion(mixed $valeur, bool $condition = false): bool
{
    if (! is_string($valeur)) {
        return false;
    }

    if ($condition) {
        return mysqlMatriceExpressionLitLaVersion($valeur);
    }

    preg_match_all('/\$\{\{(.*?)\}\}/s', $valeur, $expressions);

    return array_filter($expressions[1], mysqlMatriceExpressionLitLaVersion(...)) !== [];
}

/**
 * Vrai quand cette image est celle d'un serveur MySQL, qualifiée ou non
 * (`docker.io/library/mysql`), à une étiquette ou à un digest, ou qu'elle lit
 * la version de la matrice.
 */
function mysqlMatriceEstUneImageDeMysql(mixed $image): bool
{
    return is_string($image)
        && (preg_match('#^(?:(?:docker\.io/)?library/)?mysql(?:[:@]|$)#i', $image) === 1 || mysqlMatriceTexteLitLaVersion($image));
}

/**
 * Les bases d'un job, l'image par chemin : chaque service dont l'image est
 * celle de MySQL, quel que soit son nom, et le conteneur du job s'il l'est.
 *
 * @param  array<mixed>  $job
 * @return array<string, string>
 */
function mysqlMatriceBasesDuJob(array $job): array
{
    $bases = [];

    foreach (mysqlMatriceTableau($job['services'] ?? null) as $identifiant => $service) {
        $image = is_array($service) ? ($service['image'] ?? null) : null;

        if (is_string($image) && mysqlMatriceEstUneImageDeMysql($image)) {
            $bases["services.{$identifiant}.image"] = $image;
        }
    }

    $conteneur = $job['container'] ?? null;
    $image = is_array($conteneur) ? ($conteneur['image'] ?? null) : $conteneur;

    if (is_string($image) && mysqlMatriceEstUneImageDeMysql($image)) {
        $bases[is_array($conteneur) ? 'container.image' : 'container'] = $image;
    }

    return $bases;
}

/**
 * Les jobs d'un workflow qui ont une base, par identifiant.
 *
 * @param  array<mixed>  $workflow
 * @return array<string, array<mixed>>
 */
function mysqlMatriceJobsAvecUneBase(array $workflow): array
{
    $avecUneBase = [];

    foreach (mysqlMatriceTableau($workflow['jobs'] ?? null) as $identifiant => $job) {
        if (is_string($identifiant) && is_array($job) && mysqlMatriceBasesDuJob($job) !== []) {
            $avecUneBase[$identifiant] = $job;
        }
    }

    return $avecUneBase;
}

/**
 * Les jobs qui ont une base dans les workflows qui portent la matrice, par
 * « fichier : job ».
 *
 * @return array<string, array<mixed>>
 */
function mysqlMatriceJobsAvecUneBaseSurLaMatrice(): array
{
    $jobs = [];

    foreach (mysqlMatriceWorkflows() as $fichier => $workflow) {
        if (in_array($fichier, mysqlMatriceWorkflowsSurLaProduction(), true)) {
            continue;
        }

        foreach (mysqlMatriceJobsAvecUneBase($workflow) as $identifiant => $job) {
            $jobs["{$fichier} : {$identifiant}"] = $job;
        }
    }

    return $jobs;
}

/**
 * Ce qu'une matrice de versions de MySQL a de faux, par motif : vide quand elle
 * convient. La première version est celle de la production ; chaque suivante
 * est une version exacte, plus récente que la production ; la LTS visée en est
 * tant que la production ne l'a pas rejointe.
 *
 * @param  array<mixed>  $versions
 * @return array<string, string>
 */
function mysqlMatriceEcarts(string $production, array $versions, string $ltsVisee): array
{
    if (array_filter($versions, static fn (mixed $version): bool => ! is_string($version)) !== []) {
        return ['chaine' => 'Une version de la matrice n’est pas une chaîne : l’écrire entre apostrophes.'];
    }

    /** @var list<string> $liste */
    $liste = array_values($versions);
    $suivantes = array_slice($liste, 1);
    $ecarts = [];

    if (($liste[0] ?? null) !== $production) {
        $ecarts['premiere'] = "La première version de la matrice doit être celle de docker-compose.prod.yml ({$production}).";
    }

    if (array_unique($liste) !== $liste) {
        $ecarts['doublon'] = 'Une version figure deux fois dans la matrice.';
    }

    foreach ($suivantes as $version) {
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $ecarts['version-exacte'] = "{$version} suit l’éditeur sans diff : lui donner sa version exacte (X.Y.Z).";
        }

        if (version_compare($version, $production, '<=')) {
            $ecarts['plus-ancienne'] = "{$version} n’est pas plus récente que la production : aucun retour n’est possible une fois le volume mis à niveau, la tester ne protège rien.";
        }
    }

    $ligneVisee = array_filter(
        $suivantes,
        static fn (string $version): bool => implode('.', array_slice(explode('.', $version), 0, 2)) === $ltsVisee,
    );

    if (version_compare($production, $ltsVisee, '<') && $ligneVisee === []) {
        $ecarts['lts-visee'] = "La production est plus ancienne que la LTS visée ({$ltsVisee}), que la matrice ne teste pas.";
    }

    return $ecarts;
}

/**
 * Chaque valeur d'un tableau imbriqué, par chemin pointé (`steps.3.if`).
 *
 * @param  array<mixed>  $tableau
 * @return array<string, mixed>
 */
function mysqlMatriceFeuilles(array $tableau, string $prefixe = ''): array
{
    $feuilles = [];

    foreach ($tableau as $cle => $valeur) {
        $chemin = $prefixe.$cle;

        if (is_array($valeur)) {
            $feuilles = [...$feuilles, ...mysqlMatriceFeuilles($valeur, "{$chemin}.")];
        } else {
            $feuilles[$chemin] = $valeur;
        }
    }

    return $feuilles;
}

/**
 * Les chemins d'un job où lire la version de MySQL ne change rien à ce qui
 * tourne : le nom du job et de ses étapes, l'image de ses bases et le nom de
 * ses artefacts.
 *
 * @param  array<mixed>  $job
 * @return list<string>
 */
function mysqlMatriceLecturesPermises(array $job): array
{
    $permises = ['name', ...array_keys(mysqlMatriceBasesDuJob($job))];

    foreach (mysqlMatriceTableau($job['steps'] ?? null) as $rang => $etape) {
        $permises[] = "steps.{$rang}.name";
        $action = is_array($etape) ? ($etape['uses'] ?? null) : null;

        if (is_string($action) && str_starts_with($action, 'actions/upload-artifact@')) {
            $permises[] = "steps.{$rang}.with.name";
        }
    }

    return $permises;
}

/**
 * Vrai quand cette étape lance ce qui éprouve la base : la suite (`artisan
 * test`, Pest, PHPUnit), les parcours (`artisan dusk`) ou l'image (`docker
 * run`). Les commentaires du script ne comptent pas.
 */
function mysqlMatriceEtapeQuiTeste(mixed $etape): bool
{
    $script = is_array($etape) ? ($etape['run'] ?? null) : null;

    if (! is_string($script)) {
        return false;
    }

    $commandes = (string) preg_replace('/^\s*#.*$/m', '', $script);

    return preg_match('/\bartisan\s+(?:test|dusk)(?![\w:-])|\bvendor\/bin\/(?:pest|phpunit)\b|\bdocker\s+run\b/', $commandes) === 1;
}

/**
 * Ce qu'un job qui a une base a de faux, par motif : vide quand chaque version
 * de sa matrice y tourne et le fait tomber en échouant. Une version que
 * `exclude` retire, qu'`include` ajoute, dont l'échec ne fait pas tomber le
 * job (`continue-on-error`), ou que lit une variable d'environnement, une
 * condition ou un script, resterait écrite dans la liste sans être testée.
 *
 * @param  array<mixed>  $job
 * @return array<string, string>
 */
function mysqlMatriceDefautsDuJob(array $job): array
{
    $defauts = [];
    $bases = mysqlMatriceBasesDuJob($job);

    if ($bases === [] || array_filter($bases, static fn (string $image): bool => $image !== 'mysql:${{ matrix.mysql }}') !== []) {
        $defauts['image'] = 'Le job ne prend pas sa base dans la matrice des versions de MySQL.';
    }

    if (data_get($job, 'strategy.fail-fast') !== false) {
        $defauts['fail-fast'] = 'Le job arrêterait une version au premier échec de l’autre, qui ne dirait plus laquelle tombe.';
    }

    if (! is_array(data_get($job, 'strategy.matrix.mysql'))) {
        $defauts['versions'] = 'Les versions de MySQL ne s’écrivent pas en liste sous strategy.matrix.mysql.';
    }

    foreach (['include', 'exclude'] as $cle) {
        $entrees = data_get($job, "strategy.matrix.{$cle}");

        if ($entrees === null) {
            continue;
        }

        $nommentUneVersion = ! is_array($entrees) || array_filter(
            $entrees,
            static fn (mixed $entree): bool => ! is_array($entree) || array_key_exists('mysql', $entree),
        ) !== [];

        if ($nommentUneVersion) {
            $defauts['include-exclude'] = "Une entrée de strategy.matrix.{$cle} nomme une version de MySQL, ou ne se lit pas : la liste ne dirait plus ce qui tourne.";
        }
    }

    if (! in_array(data_get($job, 'continue-on-error'), [null, false], true)) {
        $defauts['continue-on-error'] = 'Le job porte continue-on-error : une version qui échoue ne le ferait pas tomber.';
    }

    $permises = mysqlMatriceLecturesPermises($job);
    $lectures = [];

    foreach (mysqlMatriceFeuilles($job) as $chemin => $valeur) {
        $condition = $chemin === 'if' || str_ends_with($chemin, '.if');

        if (! in_array($chemin, $permises, true) && mysqlMatriceTexteLitLaVersion($valeur, $condition)) {
            $lectures[] = $chemin;
        }
    }

    if ($lectures !== []) {
        $defauts['version-lue'] = 'La version de MySQL se lit hors du nom du job, de ses étapes et de ses artefacts et de l’image de sa base ('.implode(', ', $lectures).') : une étape pourrait se sauter, se tolérer ou changer selon elle, et cette version tourner sans être testée.';
    }

    foreach (mysqlMatriceTableau($job['steps'] ?? null) as $etape) {
        $tolere = is_array($etape) && ! in_array($etape['continue-on-error'] ?? null, [null, false], true);

        if (mysqlMatriceEtapeQuiTeste($etape) && (data_get($etape, 'if') !== null || $tolere)) {
            $defauts['etape-des-tests'] = 'L’étape qui teste porte une condition ou continue-on-error : elle pourrait se sauter ou échouer sans faire tomber le job.';
        }
    }

    return $defauts;
}

/**
 * Ce job, ou ce verdict, une fois la variante appliquée : `job` remplace des
 * clés du job, `strategie` et `matrice` des clés de `strategy` et de
 * `strategy.matrix`, `image` l'image de chaque base, `etapes` des clés de
 * chaque étape qui lance un script sans tester, `etapes-des-tests` des clés de
 * chaque étape qui teste, et `script` le script de la première étape, où
 * `{attendu}` devient le job qu'un verdict attend.
 *
 * @param  array<mixed>  $job
 * @param  array<mixed>  $variante
 * @return array<mixed>
 */
function mysqlMatriceVariante(array $job, array $variante, string $attendu = ''): array
{
    $job = array_replace($job, mysqlMatriceTableau($variante['job'] ?? null));

    if (array_key_exists('strategie', $variante)) {
        $job['strategy'] = array_replace(mysqlMatriceTableau($job['strategy'] ?? null), mysqlMatriceTableau($variante['strategie']));
    }

    if (array_key_exists('matrice', $variante)) {
        $strategie = mysqlMatriceTableau($job['strategy'] ?? null);
        $strategie['matrix'] = array_replace(mysqlMatriceTableau($strategie['matrix'] ?? null), mysqlMatriceTableau($variante['matrice']));
        $job['strategy'] = $strategie;
    }

    if (array_key_exists('image', $variante)) {
        $bases = mysqlMatriceBasesDuJob($job);
        $services = mysqlMatriceTableau($job['services'] ?? null);

        foreach ($services as $identifiant => $service) {
            if (is_array($service) && array_key_exists("services.{$identifiant}.image", $bases)) {
                $services[$identifiant] = array_replace($service, ['image' => $variante['image']]);
            }
        }

        $job['services'] = $services;
    }

    $script = $variante['script'] ?? null;
    $etapes = [];

    foreach (array_values(mysqlMatriceTableau($job['steps'] ?? null)) as $rang => $etape) {
        if (is_array($etape) && array_key_exists('run', $etape)) {
            $cles = mysqlMatriceEtapeQuiTeste($etape) ? 'etapes-des-tests' : 'etapes';
            $etape = array_replace($etape, mysqlMatriceTableau($variante[$cles] ?? null));

            if ($rang === 0 && is_string($script)) {
                $etape['run'] = str_replace('{attendu}', $attendu, $script);
            }
        }

        $etapes[] = $etape;
    }

    $job['steps'] = $etapes;

    return $job;
}

/**
 * Cette valeur si c'est un tableau, un tableau vide sinon.
 *
 * @return array<mixed>
 */
function mysqlMatriceTableau(mixed $valeur): array
{
    return is_array($valeur) ? $valeur : [];
}

it('fait tourner chaque job des workflows qui a une base sur la version de la production et sur la LTS visée', function (): void {
    $jobs = mysqlMatriceJobsAvecUneBaseSurLaMatrice();
    $production = mysqlMatriceVersionDeLaProduction();

    expect(array_keys($jobs))->toContain('ci.yml : laravel-tests-mysql', 'ci.yml : browser-shard', 'ci.yml : demarrage');

    $listes = [];

    foreach ($jobs as $identifiant => $job) {
        expect(mysqlMatriceDefautsDuJob($job))->toBe([], "`{$identifiant}` ne tourne pas sur chaque version de sa matrice.");

        $listes[$identifiant] = (array) data_get($job, 'strategy.matrix.mysql');
    }

    foreach (['ci.yml : laravel-tests-mysql', 'ci.yml : browser-shard', 'ci.yml : demarrage'] as $identifiant) {
        expect(array_filter(mysqlMatriceTableau($jobs[$identifiant]['steps'] ?? null), mysqlMatriceEtapeQuiTeste(...)))
            ->not->toBe([], "La garde ne reconnaît plus l’étape qui teste dans `{$identifiant}` : la décrire dans mysqlMatriceEtapeQuiTeste().");
    }

    $versions = array_values(array_unique(array_map(serialize(...), $listes)));
    expect($versions)->toHaveCount(1, 'Les jobs qui ont une base ne tournent pas sur les mêmes versions de MySQL : '.json_encode($listes));

    expect(mysqlMatriceEcarts($production, (array) reset($listes), mysqlMatriceLtsVisee()))->toBe([]);
});

it('reconnaît une base à l’image de son service ou du conteneur du job, quel que soit son nom', function (array $job, bool $aUneBase): void {
    expect(mysqlMatriceBasesDuJob($job) !== [])->toBe($aUneBase);
})->with([
    'un service db' => [['services' => ['db' => ['image' => 'mysql:8.4']]], true],
    'un service database, l’image qualifiée' => [['services' => ['database' => ['image' => 'docker.io/library/mysql:9.7.2']]], true],
    'une image par digest' => [['services' => ['db' => ['image' => 'mysql@sha256:'.str_repeat('a', 64)]]], true],
    'une image qui lit la version' => [['services' => ['db' => ['image' => "\${{ format('mysql:{0}', matrix.mysql) }}"]]], true],
    'le conteneur du job' => [['container' => 'mysql:8.4'], true],
    'le conteneur du job, avec ses clés' => [['container' => ['image' => 'mysql:8.4']], true],
    'redis seul' => [['services' => ['redis' => ['image' => 'redis:8-alpine']]], false],
    'mariadb' => [['services' => ['db' => ['image' => 'mariadb:11.8']]], false],
    'un conteneur node' => [['container' => 'node:24'], false],
    'sans service' => [['runs-on' => 'ubuntu-latest'], false],
]);

it('suit une base dont le service change de nom, et un job ajouté qui en prend une hors de la matrice', function (): void {
    $ci = mysqlMatriceWorkflows()['ci.yml'] ?? [];
    $jobsDeLaCi = mysqlMatriceTableau($ci['jobs'] ?? null);
    $suite = mysqlMatriceTableau($jobsDeLaCi['laravel-tests-mysql'] ?? null);

    $identifiant = explode('.', (string) array_key_first(mysqlMatriceBasesDuJob($suite)))[1] ?? '';
    $services = mysqlMatriceTableau($suite['services'] ?? null);
    $services['base-renommee'] = $services[$identifiant] ?? null;
    unset($services[$identifiant]);
    $suite['services'] = $services;
    $jobsDeLaCi['laravel-tests-mysql'] = $suite;

    expect(mysqlMatriceJobsAvecUneBase(['jobs' => $jobsDeLaCi]))->toHaveKey('laravel-tests-mysql')
        ->and(mysqlMatriceDefautsDuJob($suite))->toBe([]);

    $jobsDeLaCi['migrations-a-blanc'] = [
        'runs-on' => 'ubuntu-latest',
        'services' => ['db' => ['image' => 'mysql:8.4', 'env' => ['MYSQL_ROOT_PASSWORD' => 'password'], 'ports' => ['3306:3306']]],
        'steps' => [['uses' => 'actions/checkout@v7'], ['run' => 'php artisan migrate --force']],
    ];
    $jobs = mysqlMatriceJobsAvecUneBase(['jobs' => $jobsDeLaCi]);

    expect($jobs)->toHaveKey('migrations-a-blanc')
        ->and(array_keys(mysqlMatriceDefautsDuJob(mysqlMatriceTableau($jobs['migrations-a-blanc'] ?? null))))->toEqualCanonicalizing(['image', 'fail-fast', 'versions']);
});

/*
 * Chaque variante s'applique aux trois jobs de `ci.yml` qui ont une base. Les
 * premières vident la LTS visée en gardant la liste intacte : de la façon que
 * GitHub documente pour une version « expérimentale », par une variable
 * d'environnement qui recopie la version, par le rang du job dans la matrice,
 * ou par un script qui sort en succès.
 */
it('refuse une version que la matrice écrit sans la tester', function (array $variante, array $motifs): void {
    $ci = mysqlMatriceWorkflows()['ci.yml'] ?? [];
    $jobs = mysqlMatriceJobsAvecUneBase($ci);

    expect($jobs)->not->toBe([]);

    foreach ($jobs as $identifiant => $job) {
        expect(array_keys(mysqlMatriceDefautsDuJob(mysqlMatriceVariante($job, $variante))))->toEqualCanonicalizing($motifs, "`{$identifiant}`");
    }
})->with([
    'la LTS visée retirée par exclude' => [['matrice' => ['exclude' => [['mysql' => '9.7.2']]]], ['include-exclude']],
    'le job toléré en échec sur la LTS visée' => [['job' => ['continue-on-error' => "\${{ matrix.mysql == '9.7.2' }}"]], ['continue-on-error', 'version-lue']],
    'l’étape des tests sautée sur la LTS visée' => [['etapes-des-tests' => ['if' => "matrix.mysql == '8.4'"]], ['version-lue', 'etape-des-tests']],
    'la version recopiée dans l’environnement du job, que lit une condition' => [['job' => ['env' => ['VERSION_MYSQL' => '${{ matrix.mysql }}']], 'etapes' => ['if' => "env.VERSION_MYSQL != '9.7.2'"]], ['version-lue']],
    'la version recopiée dans l’environnement d’une étape' => [['etapes' => ['env' => ['VERSION_MYSQL' => '${{ matrix.mysql }}']]], ['version-lue']],
    'une étape sautée selon le rang du job dans la matrice' => [['etapes' => ['if' => 'strategy.job-index == 0']], ['version-lue']],
    'un script qui sort en succès sur la LTS visée' => [['etapes-des-tests' => ['run' => "[ \"\${{ matrix.mysql }}\" != \"9.7.2\" ] || exit 0\nphp artisan test -p"]], ['version-lue']],
    'la version écrite dans GITHUB_ENV par un script' => [['etapes' => ['run' => 'echo "VERSION_MYSQL=${{ matrix.mysql }}" >> "$GITHUB_ENV"']], ['version-lue']],
    'l’étape des tests tolérée en échec sur chaque version' => [['etapes-des-tests' => ['continue-on-error' => true]], ['etape-des-tests']],
    'l’étape des tests sous condition' => [['etapes-des-tests' => ['if' => 'success()']], ['etape-des-tests']],
    'une version ajoutée par include' => [['matrice' => ['include' => [['mysql' => '9.6.0']]]], ['include-exclude']],
    'un exclude écrit par une expression' => [['matrice' => ['exclude' => '${{ fromJSON(vars.EXCLUS) }}']], ['include-exclude']],
    'le job toléré en échec sur chaque version' => [['job' => ['continue-on-error' => true]], ['continue-on-error']],
    'une étape sautée, la version lue entre crochets' => [['etapes' => ['if' => "\${{ matrix['mysql'] != '9.7.2' }}"]], ['version-lue']],
    'une étape sautée, la matrice lue en entier' => [['etapes' => ['if' => "\${{ !contains(toJSON(matrix), '9.7') }}"]], ['version-lue']],
    'une étape tolérée en échec sur la LTS visée' => [['etapes' => ['continue-on-error' => "\${{ matrix.mysql == '9.7.2' }}"]], ['version-lue']],
    'la base figée sur la production' => [['image' => 'mysql:8.4'], ['image']],
    'fail-fast à vrai' => [['strategie' => ['fail-fast' => true]], ['fail-fast']],
    'les versions écrites par une expression' => [['matrice' => ['mysql' => '${{ fromJSON(vars.VERSIONS) }}']], ['versions']],
    'accepté : un exclude qui ne nomme pas de version' => [['matrice' => ['exclude' => [['shard' => 3]]]], []],
    'accepté : continue-on-error à faux' => [['job' => ['continue-on-error' => false], 'etapes-des-tests' => ['continue-on-error' => false]], []],
    'accepté : une étape qui ne tourne qu’en échec' => [['etapes' => ['if' => 'failure()']], []],
    'accepté : une autre clé de la matrice lue par un script' => [['etapes' => ['run' => 'echo "éclat ${{ matrix.shard }}"']], []],
    'accepté : une condition qui cite la matrice dans une chaîne' => [['etapes' => ['if' => "!contains(github.event.head_commit.message, 'matrix.mysql')"]], []],
    'accepté : la version dans le nom d’une étape' => [['etapes-des-tests' => ['name' => 'Tests (MySQL ${{ matrix.mysql }})']], []],
]);

/*
 * Chaque étape de la montée écrit sa matrice : avant la bascule, la production
 * et la LTS visée ; après, la production seule. La PR de la bascule suit la
 * marche de `.ai/rules/dependencies.md` sans toucher à la garde.
 */
it('accepte la matrice de chaque étape de la montée et refuse ce qui la trahit', function (string $production, array $versions, array $motifs): void {
    expect(array_keys(mysqlMatriceEcarts($production, $versions, '9.7')))->toEqualCanonicalizing($motifs);
})->with([
    'avant la bascule' => ['8.4', ['8.4', '9.7.2'], []],
    'après la bascule, la production seule' => ['9.7', ['9.7'], []],
    'après la bascule, la production à sa version exacte' => ['9.7.2', ['9.7.2'], []],
    'après la bascule, 8.4 gardée en seconde' => ['9.7', ['9.7', '8.4'], ['version-exacte', 'plus-ancienne']],
    'la LTS visée manque' => ['8.4', ['8.4'], ['lts-visee']],
    'une version d’innovation à la place de la LTS visée' => ['8.4', ['8.4', '9.6.0'], ['lts-visee']],
    'la LTS visée en étiquette flottante' => ['8.4', ['8.4', '9.7'], ['version-exacte']],
    'la production pas en tête' => ['8.4', ['9.7.2', '8.4'], ['premiere', 'version-exacte', 'plus-ancienne', 'lts-visee']],
    'une version en double' => ['8.4', ['8.4', '9.7.2', '9.7.2'], ['doublon']],
    'une version écrite en nombre' => ['8.4', ['8.4', 9.7], ['chaine']],
]);

it('développe sous Sail sur la version de MySQL de la production', function (): void {
    expect(data_get(Yaml::parseFile(base_path('compose.yaml')), 'services.mysql.image'))
        ->toBe('mysql:'.mysqlMatriceVersionDeLaProduction());
});

/**
 * Les contrôles exigés que portent les jobs à matrice : chaque verdict et le
 * job qu'il attend.
 *
 * @return array<string, string>
 */
function mysqlMatriceVerdicts(): array
{
    return ['laravel-tests' => 'laravel-tests-mysql', 'browser-tests' => 'browser-shard'];
}

/**
 * Ce qu'un verdict a de faux, par motif : vide quand il garde le nom du
 * contrôle exigé et tombe dès que le job qu'il attend n'a pas réussi, sur
 * n'importe quelle version. Un job sauté compte comme réussi pour la
 * protection de branche : sans `always()`, un échec sauterait le verdict.
 *
 * @param  array<mixed>  $verdict
 * @return array<string, string>
 */
function mysqlMatriceDefautsDuVerdict(array $verdict, string $attendu): array
{
    $defauts = [];

    if (data_get($verdict, 'strategy') !== null || data_get($verdict, 'name') !== null) {
        $defauts['nom'] = 'Une matrice ou un name: renommerait le contrôle exigé.';
    }

    if (! in_array($attendu, (array) data_get($verdict, 'needs'), true)) {
        $defauts['needs'] = "Le verdict n’attend pas {$attendu}.";
    }

    if (data_get($verdict, 'if') !== 'always()') {
        $defauts['always'] = 'Sans if: always(), un échec sauterait le verdict, qui compterait comme réussi.';
    }

    if (! in_array(data_get($verdict, 'continue-on-error'), [null, false], true)) {
        $defauts['continue-on-error'] = 'Le verdict porte continue-on-error : il ne tomberait pas.';
    }

    $etapes = array_values((array) data_get($verdict, 'steps', []));
    $etape = is_array($etapes[0] ?? null) ? $etapes[0] : [];
    $cles = array_keys($etape);
    sort($cles);

    if (count($etapes) !== 1 || $cles !== ['name', 'run']) {
        $defauts['etape'] = 'Le verdict tient en une étape, son nom et son script : une condition ou un continue-on-error la laisseraient verte sur un échec.';
    }

    $script = is_string($etape['run'] ?? null) ? $etape['run'] : '';
    $lignes = array_values(array_filter(array_map(trim(...), explode("\n", $script)), static fn (string $ligne): bool => $ligne !== ''));
    $resultat = '${{ needs.'.$attendu.'.result }}';
    $affiche = preg_match('/^echo "[^"`$\\\\]*'.preg_quote($resultat, '/').'"$/u', $lignes[0] ?? '') === 1;

    if (count($lignes) !== 2 || ! $affiche || $lignes[1] !== "[ \"{$resultat}\" = \"success\" ]") {
        $defauts['script'] = "Le script affiche le résultat de {$attendu}, puis le compare à success en dernière ligne, sans rien d’autre : un `|| true` ou une ligne de plus le rendraient vert sur un échec.";
    }

    return $defauts;
}

it('garde les contrôles laravel-tests et browser-tests : des verdicts qui tombent dès qu’une version échoue', function (): void {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    foreach (mysqlMatriceVerdicts() as $verdict => $attendu) {
        $job = data_get($ci, "jobs.{$verdict}");

        expect($job)->toBeArray("`{$verdict}` manque à ci.yml.");
        assert(is_array($job));

        expect(mysqlMatriceDefautsDuVerdict($job, $attendu))->toBe([], "`{$verdict}`");
    }

    expect((array) data_get($ci, 'jobs.build.needs'))->toContain('laravel-tests', 'browser-tests');
});

it('refuse un verdict qui resterait vert sur un échec', function (array $variante, array $motifs): void {
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    foreach (mysqlMatriceVerdicts() as $verdict => $attendu) {
        $job = data_get($ci, "jobs.{$verdict}");
        assert(is_array($job));

        expect(array_keys(mysqlMatriceDefautsDuVerdict(mysqlMatriceVariante($job, $variante, $attendu), $attendu)))->toEqualCanonicalizing($motifs, "`{$verdict}`");
    }
})->with([
    'continue-on-error sur son étape' => [['etapes' => ['continue-on-error' => true]], ['etape']],
    'un || true après le test' => [['script' => "echo \"résultat : \${{ needs.{attendu}.result }}\"\n[ \"\${{ needs.{attendu}.result }}\" = \"success\" ] || true\n"], ['script']],
    'continue-on-error sur le verdict' => [['job' => ['continue-on-error' => true]], ['continue-on-error']],
    'son étape sous condition' => [['etapes' => ['if' => false]], ['etape']],
    'une ligne après le test' => [['script' => "echo \"résultat : \${{ needs.{attendu}.result }}\"\n[ \"\${{ needs.{attendu}.result }}\" = \"success\" ]\nexit 0\n"], ['script']],
    'le test sans l’affichage du résultat' => [['script' => "[ \"\${{ needs.{attendu}.result }}\" = \"success\" ]\n"], ['script']],
    'une sortie sur la ligne de l’affichage' => [['script' => "echo \"résultat : \${{ needs.{attendu}.result }}\"; exit 0\n[ \"\${{ needs.{attendu}.result }}\" = \"success\" ]\n"], ['script']],
    'sans always()' => [['job' => ['if' => 'success()']], ['always']],
    'une matrice sur le verdict' => [['job' => ['strategy' => ['matrix' => ['mysql' => ['8.4']]]]], ['nom']],
    'un nom sur le verdict' => [['job' => ['name' => 'Verdict']], ['nom']],
    'il n’attend pas le job' => [['job' => ['needs' => ['setup-php']]], ['needs']],
    'accepté : un autre libellé' => [['script' => "echo \"chaque version : \${{ needs.{attendu}.result }}\"\n[ \"\${{ needs.{attendu}.result }}\" = \"success\" ]\n"], []],
]);

it('nomme à part les artefacts de chaque version de MySQL', function (): void {
    $artefacts = [];

    foreach (mysqlMatriceJobsAvecUneBaseSurLaMatrice() as $identifiant => $job) {
        foreach ((array) data_get($job, 'steps', []) as $etape) {
            $action = data_get($etape, 'uses');
            $nom = data_get($etape, 'with.name');

            if (is_string($action) && str_starts_with($action, 'actions/upload-artifact@')) {
                $artefacts[] = "{$identifiant} : ".(is_string($nom) ? $nom : '');
            }
        }
    }

    expect($artefacts)->not->toBe([])
        ->and(array_values(array_filter($artefacts, static fn (string $nom): bool => ! str_contains($nom, '${{ matrix.mysql }}'))))
        ->toBe([], 'Deux versions de MySQL écriraient le même artefact, et la seconde échouerait.');
});

/*
 * La passe nocturne juge les tests, que la CI fait déjà tourner sur chaque
 * version ; la doubler doublerait des parts de trente à soixante minutes. Elle
 * suit la production, et la suivra à la bascule.
 */
it('fait tourner la passe nocturne sur la version de MySQL de la production', function (): void {
    $workflows = mysqlMatriceWorkflows();
    $bases = [];

    foreach (mysqlMatriceWorkflowsSurLaProduction() as $fichier) {
        expect($workflows)->toHaveKey($fichier);

        foreach (mysqlMatriceJobsAvecUneBase($workflows[$fichier] ?? []) as $identifiant => $job) {
            foreach (mysqlMatriceBasesDuJob($job) as $chemin => $image) {
                $bases["{$fichier} : {$identifiant} : {$chemin}"] = $image;
            }
        }
    }

    expect($bases)->not->toBe([])
        ->and(array_unique(array_values($bases)))->toBe(['mysql:'.mysqlMatriceVersionDeLaProduction()], 'Une base de la passe nocturne ne tourne pas sur la version de la production : '.json_encode($bases));
});
