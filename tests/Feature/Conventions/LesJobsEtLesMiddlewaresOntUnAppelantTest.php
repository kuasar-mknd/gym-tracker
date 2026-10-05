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
 *   fichier, dans `app/`, `routes/` ou `bootstrap/`, sous l'une de ces formes :
 *   `Job::dispatch…()` ou `Job::withChain()` ; ou `new Job(…)`, `Job::class`
 *   ou son nom complet en chaîne passés directement, tableau compris, à
 *   `dispatch()`, `dispatch_sync()`, `Bus::chain()`, `Bus::batch()`,
 *   `Bus::dispatch…()`, `Schedule::job()`, `->job()` ou `->dispatch…()`.
 *   Un `new Job` qui n'est passé à aucun de ces appels ne compte pas : rangé
 *   dans une variable, passé au constructeur d'un autre objet, construit dans
 *   une fonction anonyme ou fléchée ;
 * - chaque middleware concret de `app/Http/Middleware` est nommé
 *   (`Middleware::class`, ou son nom complet en chaîne) là où Laravel branche
 *   un middleware : `bootstrap/`, `routes/`, `config/`, un fournisseur de
 *   `app/Providers`, ou un contrôleur de `app/Http/Controllers`.
 *
 * Elle ne voit pas un nom assemblé à l'exécution (`"App\\Jobs\\{$nom}"`), ni un
 * job rangé dans une variable avant son envoi (l'envoyer là où il est
 * construit), ni un envoi fait seulement depuis un test : un tel job n'a pas
 * d'appelant en production. Une classe qui doit rester sans appelant s'inscrit
 * dans `appelantsExceptions()`, avec sa raison ; une exception qui a retrouvé
 * un appelant, ou dont la classe a disparu, fait échouer la garde.
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
 * Les noms de classe qu'un fichier emploie dans son code.
 *
 * @return list<array{nom: string, avant: string, apres: string, membre: string, appel: string}>
 */
function appelantsNomsEmployesDans(string $fichier): array
{
    /** @var array<string, list<array{nom: string, avant: string, apres: string, membre: string, appel: string}>> $dejaLus */
    static $dejaLus = [];

    return $dejaLus[$fichier] ??= appelantsEmploisDuSource((string) file_get_contents($fichier));
}

/**
 * Résout un nom de classe tel qu'il est écrit, par l'espace de noms et les `use`.
 *
 * @param  array<string, string>  $imports  Alias => nom complet.
 */
function appelantsNomResolu(PhpToken $jeton, string $espace, array $imports): string
{
    if ($jeton->is(T_NAME_FULLY_QUALIFIED)) {
        return ltrim($jeton->text, '\\');
    }

    $segments = explode('\\', $jeton->text);
    $premier = strtolower($segments[0]);

    if (isset($imports[$premier])) {
        $segments[0] = $imports[$premier];

        return implode('\\', $segments);
    }

    return ltrim($espace.'\\'.$jeton->text, '\\');
}

/**
 * L'appel qu'ouvre la parenthèse en position `$i` : `dispatch` pour une
 * fonction, `bus::chain` pour une méthode statique (classe réduite à son
 * dernier segment), `->job` pour une méthode d'objet, `autre` pour un
 * constructeur, une déclaration ou une structure de contrôle.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array<string, string>  $imports
 */
function appelantsAppelOuvertEn(array $jetons, int $i, string $espace, array $imports): string
{
    $nom = $jetons[$i - 1] ?? null;
    $avant = $jetons[$i - 2] ?? null;

    if ($nom === null || ! $nom->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return $nom !== null && $nom->is(T_MATCH) ? 'match' : 'autre';
    }

    $membre = strtolower($nom->text);

    if ($avant !== null && $avant->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return '->'.$membre;
    }

    if ($avant !== null && $avant->is(T_DOUBLE_COLON)) {
        $classe = $jetons[$i - 3] ?? null;
        $resolue = $classe !== null && $classe->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            ? appelantsNomResolu($classe, $espace, $imports)
            : '';

        return strtolower(substr((string) strrchr('\\'.$resolue, '\\'), 1)).'::'.$membre;
    }

    if ($avant !== null && $avant->is([T_NEW, T_FUNCTION, T_FN])) {
        return 'autre';
    }

    return ltrim($membre, '\\');
}

/**
 * Les noms de classe qu'un source emploie dans son code, résolus, avec ce qui
 * les entoure : le jeton d'avant (`new`), les deux d'après (`::`, `dispatch`
 * ou `class`), et l'appel dont ils sont directement l'argument (`appel`, vide
 * hors de tout appel, `autre` quand une fonction anonyme ou fléchée, un bloc
 * ou un constructeur s'interpose). Une chaîne littérale compte comme un nom
 * quand elle en a la forme.
 *
 * @return list<array{nom: string, avant: string, apres: string, membre: string, appel: string}>
 */
function appelantsEmploisDuSource(string $source): array
{
    $jetons = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $jeton): bool => ! $jeton->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
    ));
    $espace = '';
    $imports = [];
    $profondeur = 0;
    $emplois = [];
    $nombre = count($jetons);

    /**
     * Une case par parenthèse, crochet ou accolade ouverts : l'appel ouvert,
     * `[` pour un tableau ou le corps d'un `match` (transparents), `{` pour un
     * bloc (opaque), et si une fonction fléchée a commencé à ce niveau depuis
     * la dernière virgule (elle court jusqu'à la virgule ou à la fermeture).
     *
     * @var list<array{appel: string, flechee: bool}> $pile
     */
    $pile = [];
    $dernierAppelFerme = '';

    for ($i = 0; $i < $nombre; $i++) {
        $jeton = $jetons[$i];

        if ($jeton->is(T_ARRAY) && ($jetons[$i + 1]->text ?? '') === '(') {
            $pile[] = ['appel' => '[', 'flechee' => false];
            $i++;

            continue;
        }

        if ($jeton->text === '{' || $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $profondeur++;
            $corpsDeMatch = $jeton->text === '{' && ($jetons[$i - 1]->text ?? '') === ')' && $dernierAppelFerme === 'match';
            $pile[] = ['appel' => $corpsDeMatch ? '[' : '{', 'flechee' => false];
        } elseif ($jeton->text === '}') {
            $profondeur--;
            array_pop($pile);
        } elseif ($jeton->text === '(') {
            $pile[] = ['appel' => appelantsAppelOuvertEn($jetons, $i, $espace, $imports), 'flechee' => false];
        } elseif ($jeton->text === '[' || $jeton->is(T_ATTRIBUTE)) {
            $pile[] = ['appel' => '[', 'flechee' => false];
        } elseif ($jeton->text === ')' || $jeton->text === ']') {
            $dernierAppelFerme = array_pop($pile)['appel'] ?? '';
        } elseif (($jeton->is(T_FN) || $jeton->text === ',') && $pile !== []) {
            $sommet = array_key_last($pile);
            $pile[$sommet] = ['appel' => $pile[$sommet]['appel'], 'flechee' => $jeton->is(T_FN)];
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

        if ($jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $nom = appelantsNomResolu($jeton, $espace, $imports);
        } elseif ($jeton->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/^([\'"])\\\\{0,2}(App(?:\\\\{1,2}\w+)+)\1$/', $jeton->text, $chaine) === 1) {
            $nom = str_replace('\\\\', '\\', $chaine[2]);
        }

        if ($nom !== null) {
            $emplois[] = [
                'nom' => strtolower($nom),
                'avant' => strtolower($jetons[$i - 1]->text ?? ''),
                'apres' => $jeton->is(T_CONSTANT_ENCAPSED_STRING) ? '::' : ($jetons[$i + 1]->text ?? ''),
                'membre' => $jeton->is(T_CONSTANT_ENCAPSED_STRING) ? 'class' : strtolower($jetons[$i + 2]->text ?? ''),
                'appel' => appelantsAppelEnglobant($pile),
            ];
        }
    }

    return $emplois;
}

/**
 * L'appel dont la position courante est directement l'argument : la case la
 * plus haute de la pile qui n'est pas un tableau ; `autre` si une fonction
 * fléchée s'interpose.
 *
 * @param  list<array{appel: string, flechee: bool}>  $pile
 */
function appelantsAppelEnglobant(array $pile): string
{
    foreach (array_reverse($pile) as $case) {
        if ($case['flechee']) {
            return 'autre';
        }

        if ($case['appel'] !== '[') {
            return $case['appel'];
        }
    }

    return '';
}

/**
 * Vrai si cet emploi d'un job l'envoie : `Job::dispatch…()`, `Job::withChain()`,
 * ou `new Job` / `Job::class` passé directement à un appel qui envoie.
 *
 * @param  array{nom: string, avant: string, apres: string, membre: string, appel: string}  $emploi
 */
function appelantsEnvoieLeJob(array $emploi): bool
{
    $nomme = $emploi['avant'] === 'new' || ($emploi['apres'] === '::' && $emploi['membre'] === 'class');

    if (! $nomme) {
        return $emploi['apres'] === '::' && (str_starts_with($emploi['membre'], 'dispatch') || $emploi['membre'] === 'withchain');
    }

    $appel = $emploi['appel'];

    return in_array($appel, ['dispatch', 'dispatch_sync', 'bus::chain', 'bus::batch', 'schedule::job', '->job'], true)
        || str_starts_with($appel, 'bus::dispatch')
        || str_starts_with($appel, '->dispatch');
}

/**
 * Les classes données qu'aucun fichier ne sollicite comme le demande le test.
 *
 * @param  array<string, string>  $classes  Nom complet => fichier de la classe.
 * @param  list<string>  $fichiers
 * @param  Closure(array{nom: string, avant: string, apres: string, membre: string, appel: string}): bool  $sollicite
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
        appelantsEnvoieLeJob(...),
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

it('ne compte un job comme envoyé que passé à un appel qui l envoie', function (string $code, bool $envoye): void {
    $source = implode("\n", [
        '<?php',
        'namespace App\\Console;',
        'use App\\Jobs\\SondeDeLaGarde;',
        'use Illuminate\\Support\\Facades\\Bus;',
        'use Illuminate\\Support\\Facades\\Schedule;',
        $code,
    ]);
    $emplois = array_filter(
        appelantsEmploisDuSource($source),
        static fn (array $emploi): bool => $emploi['nom'] === 'app\\jobs\\sondedelagarde',
    );

    expect(array_filter($emplois, appelantsEnvoieLeJob(...)) !== [])->toBe($envoye);
})->with([
    'new Job() jamais envoyé' => ['$sonde = new SondeDeLaGarde();', false],
    'Job::class hors de tout envoi' => ['$classe = SondeDeLaGarde::class;', false],
    'new Job() passé au constructeur d un autre objet' => ['dispatch(new Autre(new SondeDeLaGarde()));', false],
    'new Job() construit dans une fonction anonyme envoyée' => ['dispatch(function () { return new SondeDeLaGarde(); });', false],
    'new Job() construit dans une fonction fléchée envoyée' => ['dispatch(fn () => new SondeDeLaGarde());', false],
    'new Job() passé à un appel qui n envoie pas' => ['$journal->info(new SondeDeLaGarde());', false],
    'envoi en commentaire' => ['// dispatch(new SondeDeLaGarde());', false],
    'Schedule::job(Job::class)' => ['Schedule::job(SondeDeLaGarde::class)->daily();', true],
    'Schedule::job(new Job())' => ['Schedule::job(new SondeDeLaGarde())->daily();', true],
    'Schedule::job() par le nom complet en chaîne' => ["Schedule::job('App\\Jobs\\SondeDeLaGarde')->daily();", true],
    '$schedule->job(new Job())' => ['$schedule->job(new SondeDeLaGarde());', true],
    'dispatch(new Job())' => ['dispatch(new SondeDeLaGarde($compte));', true],
    'dispatch_sync(new Job())' => ['dispatch_sync(new SondeDeLaGarde($compte));', true],
    'dispatch() d un match' => ['dispatch(match ($cas) { 1 => new SondeDeLaGarde(), default => new Autre() });', true],
    'Bus::chain([…, new Job()])' => ['Bus::chain([new Autre(), new SondeDeLaGarde()])->dispatch();', true],
    'Bus::chain() après une fonction fléchée' => ['Bus::chain([fn () => new Autre(), new SondeDeLaGarde()])->dispatch();', true],
    'Bus::batch([new Job()])' => ['Bus::batch([new SondeDeLaGarde()])->dispatch();', true],
    'Bus::dispatchSync(new Job())' => ['Bus::dispatchSync(new SondeDeLaGarde());', true],
    '$bus->dispatch(new Job())' => ['$bus->dispatch(new SondeDeLaGarde());', true],
    'Job::dispatch()' => ['SondeDeLaGarde::dispatch($compte);', true],
    'Job::dispatchIf()' => ['SondeDeLaGarde::dispatchIf($condition, $compte);', true],
    'Job::withChain()' => ['SondeDeLaGarde::withChain([new Autre()])->dispatch($compte);', true],
]);

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
