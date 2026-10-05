<?php

declare(strict_types=1);

use App\Http\Middleware\PulseNonceMiddleware;
use Symfony\Component\Finder\Finder;

/*
 * Un job que rien n'envoie, un middleware que rien ne branche (#1991).
 *
 * Le détecteur de code mort de PHPStan (`phpstan.neon`) tient pour utilisés le
 * constructeur et `handle()` de toute classe `ShouldQueue`, et `handle()` de
 * toute classe dont le premier paramètre est une `Request` : c'est ainsi que
 * Laravel les appelle. Un job jamais envoyé ou un middleware jamais branché
 * passe donc l'analyse sans bruit. `RecalculateUserStats` a vécu ainsi : jamais
 * envoyé, sans test, et refait en ligne par les quatre écritures de séance.
 *
 * Cette garde lit le code (commentaires écartés, noms résolus par `namespace`
 * et `use`) :
 *
 * - chaque job concret de `app/Jobs` est envoyé ailleurs que dans son propre
 *   fichier, dans `app/`, `routes/` ou `bootstrap/` : `Job::dispatch…()`, ou
 *   `new Job(…)` passé à `dispatch()`, `Bus::chain()`, `Schedule::job()`… ;
 * - chaque middleware concret de `app/Http/Middleware` est nommé
 *   (`Middleware::class`, ou son nom complet en chaîne) là où Laravel branche
 *   un middleware : `bootstrap/`, `routes/`, `config/`, un fournisseur de
 *   `app/Providers`, ou un contrôleur de `app/Http/Controllers`.
 *
 * Elle ne voit pas un nom assemblé à l'exécution (`"App\\Jobs\\{$nom}"`), ni un
 * envoi fait seulement depuis un test : un tel job n'a pas d'appelant en
 * production. Une classe qui doit rester sans appelant s'inscrit dans
 * `appelantsExceptions()`, avec sa raison ; une exception qui a retrouvé un
 * appelant, ou dont la classe a disparu, fait échouer la garde.
 */

/**
 * Les classes qui restent sans appelant, chacune avec sa raison.
 *
 * @return array<string, string>
 */
function appelantsExceptions(): array
{
    return [
        PulseNonceMiddleware::class => 'Débranché depuis que le précompilateur signe les scripts de Pulse à la compilation de ses gabarits. '
            ."Sa suppression, et celle de son test, attendent l'accord du propriétaire du dépôt (#1991).",
    ];
}

/**
 * Les classes concrètes déclarées sous un dossier de `app/`, par nom complet.
 *
 * @return array<string, string> Nom complet => chemin du fichier.
 */
function appelantsClassesConcretesDe(string $dossier, string $espace): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path($dossier))->name('*.php') as $fichier) {
        $relatif = str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());
        $classe = $espace.'\\'.$relatif;

        if (class_exists($classe) && new ReflectionClass($classe)->isInstantiable()) {
            $classes[$classe] = $fichier->getRealPath();
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * Les fichiers PHP de dossiers relatifs à la racine du dépôt.
 *
 * @param  list<string>  $dossiers
 * @return list<string>
 */
function appelantsFichiersDe(array $dossiers): array
{
    $fichiers = [];

    foreach (Finder::create()->files()->in(array_map(base_path(...), $dossiers))->name('*.php') as $fichier) {
        $fichiers[] = (string) $fichier->getRealPath();
    }

    sort($fichiers);

    return $fichiers;
}

/**
 * Lit les `use` d'import d'une instruction, groupes et alias compris.
 *
 * @return array<string, string> Alias => nom complet.
 */
function appelantsImportsDe(string $instruction): array
{
    $instruction = trim((string) preg_replace('/\s+/', ' ', $instruction));

    if (preg_match('/^(function|const)\b/i', $instruction) === 1) {
        return [];
    }

    $prefixe = '';

    if (preg_match('/^(.*?)\\\\?\s*\{(.*)\}$/', $instruction, $groupe) === 1) {
        $prefixe = trim($groupe[1], ' \\').'\\';
        $instruction = $groupe[2];
    }

    $imports = [];

    foreach (explode(',', $instruction) as $element) {
        $parties = preg_split('/\s+as\s+/i', trim($element));
        $parties = $parties === false ? [] : $parties;
        $nom = ltrim($prefixe.trim($parties[0] ?? ''), '\\');

        if ($nom === '' || $nom === '\\') {
            continue;
        }

        $alias = trim($parties[1] ?? '');

        if ($alias === '') {
            $alias = substr((string) strrchr('\\'.$nom, '\\'), 1);
        }

        $imports[strtolower($alias)] = $nom;
    }

    return $imports;
}

/**
 * Les noms de classe qu'un fichier emploie dans son code, résolus, avec ce qui
 * les entoure : le jeton d'avant (`new`), et les deux d'après (`::`, `dispatch`
 * ou `class`). Une chaîne littérale compte comme un nom quand elle en a la forme.
 *
 * @return list<array{nom: string, avant: string, apres: string, membre: string}>
 */
function appelantsNomsEmployesDans(string $fichier): array
{
    /** @var array<string, list<array{nom: string, avant: string, apres: string, membre: string}>> $dejaLus */
    static $dejaLus = [];

    if (isset($dejaLus[$fichier])) {
        return $dejaLus[$fichier];
    }

    $source = (string) file_get_contents($fichier);
    $jetons = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $jeton): bool => ! $jeton->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
    ));
    $espace = '';
    $imports = [];
    $profondeur = 0;
    $emplois = [];
    $nombre = count($jetons);

    for ($i = 0; $i < $nombre; $i++) {
        $jeton = $jetons[$i];

        if ($jeton->text === '{' || $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $profondeur++;
        } elseif ($jeton->text === '}') {
            $profondeur--;
        }

        if ($jeton->is(T_NAMESPACE) && isset($jetons[$i + 1]) && $jetons[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
            $espace = $jetons[$i + 1]->text;
            $i++;

            continue;
        }

        if ($jeton->is(T_USE) && $profondeur === 0 && ($jetons[$i + 1]->text ?? '(') !== '(') {
            $fin = $i + 1;

            while ($fin < $nombre && $jetons[$fin]->text !== ';') {
                $fin++;
            }

            $debut = $jetons[$i + 1]->pos;
            $imports = [...$imports, ...appelantsImportsDe(substr($source, $debut, ($jetons[$fin]->pos ?? strlen($source)) - $debut))];
            $i = $fin;

            continue;
        }

        $nom = null;

        if ($jeton->is(T_NAME_FULLY_QUALIFIED)) {
            $nom = ltrim($jeton->text, '\\');
        } elseif ($jeton->is([T_STRING, T_NAME_QUALIFIED])) {
            $segments = explode('\\', $jeton->text);
            $premier = strtolower($segments[0]);

            if (isset($imports[$premier])) {
                $segments[0] = $imports[$premier];
                $nom = implode('\\', $segments);
            } else {
                $nom = ltrim($espace.'\\'.$jeton->text, '\\');
            }
        } elseif ($jeton->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/^([\'"])\\\\{0,2}(App(?:\\\\{1,2}\w+)+)\1$/', $jeton->text, $chaine) === 1) {
            $nom = str_replace('\\\\', '\\', $chaine[2]);
        }

        if ($nom !== null) {
            $emplois[] = [
                'nom' => strtolower($nom),
                'avant' => strtolower($jetons[$i - 1]->text ?? ''),
                'apres' => $jeton->is(T_CONSTANT_ENCAPSED_STRING) ? '::' : ($jetons[$i + 1]->text ?? ''),
                'membre' => $jeton->is(T_CONSTANT_ENCAPSED_STRING) ? 'class' : strtolower($jetons[$i + 2]->text ?? ''),
            ];
        }
    }

    return $dejaLus[$fichier] = $emplois;
}

/**
 * Les classes données qu'aucun fichier ne sollicite comme le demande le test.
 *
 * @param  array<string, string>  $classes  Nom complet => fichier de la classe.
 * @param  list<string>  $fichiers
 * @param  Closure(array{nom: string, avant: string, apres: string, membre: string}): bool  $sollicite
 * @return list<string>
 */
function appelantsClassesSansAppelant(array $classes, array $fichiers, Closure $sollicite): array
{
    $appelees = [];

    foreach ($fichiers as $fichier) {
        foreach (appelantsNomsEmployesDans($fichier) as $emploi) {
            foreach ($classes as $classe => $fichierDeLaClasse) {
                if ($fichier !== $fichierDeLaClasse && $emploi['nom'] === strtolower($classe) && $sollicite($emploi)) {
                    $appelees[$classe] = true;
                }
            }
        }
    }

    return array_values(array_diff(array_keys($classes), array_keys($appelees)));
}

/**
 * @return list<string>
 */
function appelantsJobsSansEnvoi(): array
{
    return appelantsClassesSansAppelant(
        appelantsClassesConcretesDe('Jobs', 'App\\Jobs'),
        appelantsFichiersDe(['app', 'routes', 'bootstrap']),
        static fn (array $emploi): bool => $emploi['avant'] === 'new'
            || ($emploi['apres'] === '::' && str_starts_with($emploi['membre'], 'dispatch')),
    );
}

/**
 * @return list<string>
 */
function appelantsMiddlewaresNonBranches(): array
{
    return appelantsClassesSansAppelant(
        appelantsClassesConcretesDe('Http/Middleware', 'App\\Http\\Middleware'),
        appelantsFichiersDe(['bootstrap', 'routes', 'config', 'app/Providers', 'app/Http/Controllers']),
        static fn (array $emploi): bool => $emploi['apres'] === '::' && $emploi['membre'] === 'class',
    );
}

it('reconnaît les jobs envoyés et les middlewares branchés', function (): void {
    $jobs = appelantsClassesConcretesDe('Jobs', 'App\\Jobs');
    $middlewares = appelantsClassesConcretesDe('Http/Middleware', 'App\\Http\\Middleware');

    expect($jobs)->not->toBeEmpty()
        ->and($middlewares)->not->toBeEmpty()
        ->and(array_diff(array_keys($jobs), appelantsJobsSansEnvoi()))->not->toBeEmpty()
        ->and(array_diff(array_keys($middlewares), appelantsMiddlewaresNonBranches()))->not->toBeEmpty();
});

it('ne garde aucun job que rien n envoie', function (): void {
    $orphelins = array_values(array_diff(appelantsJobsSansEnvoi(), array_keys(appelantsExceptions())));

    expect($orphelins)->toBe([], implode("\n", [
        'Ces jobs ne sont envoyés nulle part dans app/, routes/ ou bootstrap/ :',
        '  '.implode("\n  ", $orphelins),
        '',
        'Le détecteur de code mort les tient pour utilisés parce qu\'ils implémentent ShouldQueue (#1991).',
        "Les envoyer là où leur travail est attendu, ou les supprimer. S'ils doivent rester, les inscrire dans appelantsExceptions(), avec leur raison.",
    ]));
});

it('ne garde aucun middleware que rien ne branche', function (): void {
    $orphelins = array_values(array_diff(appelantsMiddlewaresNonBranches(), array_keys(appelantsExceptions())));

    expect($orphelins)->toBe([], implode("\n", [
        'Ces middlewares ne sont nommés ni dans bootstrap/, ni dans routes/, ni dans config/, ni par un fournisseur ou un contrôleur :',
        '  '.implode("\n  ", $orphelins),
        '',
        'Le détecteur de code mort tient leur handle() pour utilisé parce qu\'il reçoit une Request (#1991).',
        "Les brancher, ou les supprimer. S'ils doivent rester, les inscrire dans appelantsExceptions(), avec leur raison.",
    ]));
});

it('n excepte que des classes qui existent et restent sans appelant', function (): void {
    $sansAppelant = [...appelantsJobsSansEnvoi(), ...appelantsMiddlewaresNonBranches()];
    $sansObjet = array_values(array_filter(
        array_keys(appelantsExceptions()),
        static fn (string $classe): bool => ! in_array($classe, $sansAppelant, true),
    ));

    expect($sansObjet)->toBe([], implode("\n", [
        "Ces exceptions n'ont plus d'objet : la classe a disparu, ou elle a retrouvé un appelant.",
        '  '.implode("\n  ", $sansObjet),
        '',
        'Les retirer de appelantsExceptions() : une exception qui ne sert plus couvrirait d\'avance une classe orpheline future.',
    ]))
        ->and(array_filter(appelantsExceptions(), static fn (string $raison): bool => trim($raison) === ''))->toBe([]);
});
