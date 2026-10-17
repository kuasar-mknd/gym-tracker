<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La montée vers MySQL 9.7 commence par la CI : chaque job de `ci.yml` qui a
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
 * Une version écrite dans la liste doit aussi tourner et faire tomber son job :
 * ni `exclude` ni `include` ne la nomment, ni le job ni une étape ne se
 * tolèrent ou ne se sautent selon elle.
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
 * Les jobs d'un workflow qui ont un service MySQL, par identifiant.
 *
 * @return array<string, array<string, mixed>>
 */
function mysqlMatriceJobsAvecUneBase(string $workflow): array
{
    $jobs = data_get(Yaml::parseFile(base_path(".github/workflows/{$workflow}")), 'jobs', []);
    $avecUneBase = [];

    foreach (is_array($jobs) ? $jobs : [] as $identifiant => $job) {
        if (is_string($identifiant) && is_array($job) && data_get($job, 'services.mysql') !== null) {
            /** @var array<string, mixed> $job */
            $avecUneBase[$identifiant] = $job;
        }
    }

    return $avecUneBase;
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
 * Vrai quand cette expression lit la version de MySQL de la matrice
 * (`matrix.mysql`, `matrix['mysql']`) ou la matrice entière (`toJSON(matrix)`).
 */
function mysqlMatriceLitLaVersion(mixed $expression): bool
{
    return is_string($expression)
        && preg_match('/\bmatrix\s*(?:\.\s*mysql\b|\[\s*[\'"]mysql[\'"]\s*\])|\(\s*matrix\s*\)/', $expression) === 1;
}

/**
 * Ce qu'un job qui a une base a de faux, par motif : vide quand chaque version
 * de sa matrice y tourne et le fait tomber en échouant. Une version que
 * `exclude` retire, qu'`include` ajoute, dont l'échec ne fait pas tomber le
 * job (`continue-on-error`) ou dont une étape se saute selon la version
 * resterait écrite dans la liste sans être testée.
 *
 * @param  array<mixed>  $job
 * @return array<string, string>
 */
function mysqlMatriceDefautsDuJob(array $job): array
{
    $defauts = [];

    if (data_get($job, 'services.mysql.image') !== 'mysql:${{ matrix.mysql }}') {
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

    foreach ((array) data_get($job, 'steps', []) as $etape) {
        if (mysqlMatriceLitLaVersion(data_get($etape, 'if')) || mysqlMatriceLitLaVersion(data_get($etape, 'continue-on-error'))) {
            $defauts['etape-selon-la-version'] = 'Une étape se saute ou se tolère selon la version de MySQL : cette version tournerait sans être testée.';
        }
    }

    return $defauts;
}

/**
 * Ce job, ou ce verdict, une fois la variante appliquée : `job` remplace des
 * clés du job, `strategie` et `matrice` des clés de `strategy` et de
 * `strategy.matrix`, `image` l'image de la base, `etapes` des clés de chaque
 * étape qui lance un script, et `script` le script de la première étape, où
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
        $services = mysqlMatriceTableau($job['services'] ?? null);
        $base = mysqlMatriceTableau($services['mysql'] ?? null);
        $base['image'] = $variante['image'];
        $services['mysql'] = $base;
        $job['services'] = $services;
    }

    $script = $variante['script'] ?? null;
    $etapes = [];

    foreach (array_values(mysqlMatriceTableau($job['steps'] ?? null)) as $rang => $etape) {
        if (is_array($etape) && array_key_exists('run', $etape)) {
            $etape = array_replace($etape, mysqlMatriceTableau($variante['etapes'] ?? null));

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

it('fait tourner chaque job de la CI qui a une base sur la version de la production et sur la LTS visée', function (): void {
    $jobs = mysqlMatriceJobsAvecUneBase('ci.yml');
    $production = mysqlMatriceVersionDeLaProduction();

    expect(array_keys($jobs))->toContain('laravel-tests-mysql', 'browser-shard', 'demarrage');

    $listes = [];

    foreach ($jobs as $identifiant => $job) {
        expect(mysqlMatriceDefautsDuJob($job))->toBe([], "`{$identifiant}` ne tourne pas sur chaque version de sa matrice.");

        $listes[$identifiant] = (array) data_get($job, 'strategy.matrix.mysql');
    }

    $versions = array_values(array_unique(array_map(serialize(...), $listes)));
    expect($versions)->toHaveCount(1, 'Les jobs qui ont une base ne tournent pas sur les mêmes versions de MySQL : '.json_encode($listes));

    expect(mysqlMatriceEcarts($production, (array) reset($listes), mysqlMatriceLtsVisee()))->toBe([]);
});

/*
 * Chaque variante s'applique aux trois jobs de `ci.yml` qui ont une base. Les
 * trois premières vident la LTS visée en gardant la liste intacte, de la
 * façon que GitHub documente pour une version « expérimentale ».
 */
it('refuse une version que la matrice écrit sans la tester', function (array $variante, array $motifs): void {
    $jobs = mysqlMatriceJobsAvecUneBase('ci.yml');

    expect($jobs)->not->toBe([]);

    foreach ($jobs as $identifiant => $job) {
        expect(array_keys(mysqlMatriceDefautsDuJob(mysqlMatriceVariante($job, $variante))))->toEqualCanonicalizing($motifs, "`{$identifiant}`");
    }
})->with([
    'la LTS visée retirée par exclude' => [['matrice' => ['exclude' => [['mysql' => '9.7.2']]]], ['include-exclude']],
    'le job toléré en échec sur la LTS visée' => [['job' => ['continue-on-error' => "\${{ matrix.mysql == '9.7.2' }}"]], ['continue-on-error']],
    'une étape sautée sur la LTS visée' => [['etapes' => ['if' => "matrix.mysql == '8.4'"]], ['etape-selon-la-version']],
    'une version ajoutée par include' => [['matrice' => ['include' => [['mysql' => '9.6.0']]]], ['include-exclude']],
    'un exclude écrit par une expression' => [['matrice' => ['exclude' => '${{ fromJSON(vars.EXCLUS) }}']], ['include-exclude']],
    'le job toléré en échec sur chaque version' => [['job' => ['continue-on-error' => true]], ['continue-on-error']],
    'une étape sautée, la version lue entre crochets' => [['etapes' => ['if' => "\${{ matrix['mysql'] != '9.7.2' }}"]], ['etape-selon-la-version']],
    'une étape sautée, la matrice lue en entier' => [['etapes' => ['if' => "\${{ !contains(toJSON(matrix), '9.7') }}"]], ['etape-selon-la-version']],
    'une étape tolérée en échec sur la LTS visée' => [['etapes' => ['continue-on-error' => "\${{ matrix.mysql == '9.7.2' }}"]], ['etape-selon-la-version']],
    'la base figée sur la production' => [['image' => 'mysql:8.4'], ['image']],
    'fail-fast à vrai' => [['strategie' => ['fail-fast' => true]], ['fail-fast']],
    'les versions écrites par une expression' => [['matrice' => ['mysql' => '${{ fromJSON(vars.VERSIONS) }}']], ['versions']],
    'accepté : un exclude qui ne nomme pas de version' => [['matrice' => ['exclude' => [['shard' => 3]]]], []],
    'accepté : continue-on-error à faux' => [['job' => ['continue-on-error' => false]], []],
    'accepté : une étape qui ne tourne qu’en échec' => [['etapes' => ['if' => 'failure()']], []],
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

    foreach (mysqlMatriceJobsAvecUneBase('ci.yml') as $identifiant => $job) {
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
    $jobs = mysqlMatriceJobsAvecUneBase('mutation.yml');

    expect($jobs)->not->toBe([]);

    foreach ($jobs as $identifiant => $job) {
        expect(data_get($job, 'services.mysql.image'))->toBe('mysql:'.mysqlMatriceVersionDeLaProduction(), "`{$identifiant}` ne tourne pas sur la version de la production.");
    }
});
