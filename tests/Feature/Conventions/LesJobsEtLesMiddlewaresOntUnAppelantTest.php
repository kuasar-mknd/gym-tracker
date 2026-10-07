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
 * - chaque middleware concret de `app/Http/Middleware` est branché par un
 *   fichier de `bootstrap/`, `routes/`, `config/`, `app/Providers` ou
 *   `app/Http/Controllers`, c'est-à-dire nommé (`Middleware::class`, ou son
 *   nom complet en chaîne) là où une requête le traverse : passé directement,
 *   tableau compris, à `->append()`, `->prepend()` ou `->use()`, à
 *   `->replace()` comme remplaçant, ou à un appel dont le nom finit par
 *   `middleware` (`->middleware()`, `Route::middleware()`,
 *   `->authMiddleware()`, `->pushMiddleware()`), hors `withoutMiddleware()` et
 *   `aliasMiddleware()` ; premier argument de `new Middleware()` ou de
 *   l'attribut `#[Middleware]` d'un contrôleur ; rangé sous la clé
 *   `middleware` d'un tableau (configuration d'un paquet, attributs de
 *   `Route::group()`) ; ou écrit directement dans le corps d'une fonction dont
 *   le nom finit par `middleware` (`HasMiddleware::middleware()` d'un
 *   contrôleur, `getMiddleware()` que le panneau passe à `->middleware()`).
 *   Tout autre emploi ne compte pas, parce qu'aucune requête ne le traverse :
 *   un nom rangé dans une variable ou une constante, passé à `array_merge()`
 *   ou à `->singleton()`, ou qui ne sert qu'à retirer le middleware ou à le
 *   classer (`->withoutMiddleware()`, l'attribut `#[WithoutMiddleware]`,
 *   `->withoutMiddlewareFor()`, `->remove()`, `->removeFromGroup()`,
 *   `->priority()`, `->prependToPriorityList()`, `->appendToPriorityList()`,
 *   l'argument `search` de `->replace()` et de `->replaceInGroup()`, `remove:`
 *   et les clés de `replace:` dans `->web()` et `->api()`) ;
 * - un middleware nommé seulement par un alias (`->alias()`,
 *   `->aliasMiddleware()`) ou rangé seulement dans un groupe que l'application
 *   déclare (`->appendToGroup()`, `->prependToGroup()`, `->group()`,
 *   `->replaceInGroup()`, `->middlewareGroup()`, `->pushMiddlewareToGroup()`,
 *   `->prependMiddlewareToGroup()`) n'est branché que si une chaîne applique
 *   cet alias ou ce groupe, paramètres compris (`sonde:6,1`), à l'une des
 *   places ci-dessus (hors `->append()`, `->prepend()`, `->use()` et
 *   `->replace()`, qui ne lisent pas les alias), ou rangée dans un groupe
 *   lui-même appliqué. C'est ainsi qu'un middleware meurt le plus souvent : son
 *   alias reste dans `bootstrap/app.php` quand la dernière route qui le citait
 *   disparaît. `web` et `api`, les groupes que Laravel pose sur les fichiers de
 *   routes, comptent toujours comme appliqués.
 *
 * Elle ne voit pas un nom assemblé à l'exécution (`"App\\Jobs\\{$nom}"`), ni un
 * job rangé dans une variable avant son envoi (l'envoyer là où il est
 * construit), ni un envoi fait seulement depuis un test : un tel job n'a pas
 * d'appelant en production. De même, un middleware, un alias ou un groupe
 * tenus par une variable, une constante ou un appel intermédiaire
 * (`array_merge()`), à la déclaration comme à l'emploi, ne comptent pas, ni un
 * nom écrit dans un bloc imbriqué (`if`, `foreach`) du corps d'une fonction
 * `…Middleware()` : la garde échoue alors sur un middleware pourtant branché,
 * et l'écrire en littéral à l'une des places ci-dessus suffit. Une classe qui
 * doit rester sans appelant s'inscrit dans `appelantsExceptions()`, avec sa
 * raison ; une exception qui a retrouvé un appelant, ou dont la classe a
 * disparu, fait échouer la garde.
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
 * Les noms de classe et les chaînes qu'un fichier emploie dans son code.
 *
 * @return list<array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}>
 */
function appelantsNomsEmployesDans(string $fichier): array
{
    /** @var array<string, list<array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}>> $dejaLus */
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
 * Le dernier segment d'un nom de classe, en minuscules (`middleware` pour
 * `Illuminate\Routing\Controllers\Middleware`).
 */
function appelantsDernierSegment(string $classe): string
{
    return strtolower(substr((string) strrchr('\\'.$classe, '\\'), 1));
}

/**
 * L'appel qu'ouvre la parenthèse en position `$i` : `dispatch` pour une
 * fonction, `bus::chain` pour une méthode statique (classe réduite à son
 * dernier segment), `->job` pour une méthode d'objet, `new middleware` pour un
 * constructeur et `#middleware` pour un attribut (classe réduite de même, alias
 * d'import résolu), `autre` pour une déclaration ou une structure de contrôle.
 *
 * @param  list<PhpToken>  $jetons
 * @param  array<string, string>  $imports
 * @param  bool  $dansUnAttribut  Vrai si la parenthèse s'ouvre directement dans `#[…]`.
 */
function appelantsAppelOuvertEn(array $jetons, int $i, string $espace, array $imports, bool $dansUnAttribut): string
{
    $nom = $jetons[$i - 1] ?? null;
    $avant = $jetons[$i - 2] ?? null;

    if ($nom === null || ! $nom->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return $nom !== null && $nom->is(T_MATCH) ? 'match' : 'autre';
    }

    $membre = strtolower($nom->text);

    if ($dansUnAttribut) {
        return '#'.appelantsDernierSegment(appelantsNomResolu($nom, $espace, $imports));
    }

    if ($avant !== null && $avant->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return '->'.$membre;
    }

    if ($avant !== null && $avant->is(T_DOUBLE_COLON)) {
        $classe = $jetons[$i - 3] ?? null;
        $resolue = $classe !== null && $classe->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            ? appelantsNomResolu($classe, $espace, $imports)
            : '';

        return appelantsDernierSegment($resolue).'::'.$membre;
    }

    if ($avant !== null && $avant->is(T_NEW)) {
        return 'new '.appelantsDernierSegment(appelantsNomResolu($nom, $espace, $imports));
    }

    if ($avant !== null && $avant->is([T_FUNCTION, T_FN])) {
        return 'autre';
    }

    return ltrim($membre, '\\');
}

/**
 * Une case de la pile d'`appelantsEmploisDuSource()`.
 *
 * @return array{id: int, appel: string, flechee: bool, rang: int, etiquette: string, cle: string}
 */
function appelantsCase(int $id, string $appel, string $cle = ''): array
{
    return ['id' => $id, 'appel' => $appel, 'flechee' => false, 'rang' => 0, 'etiquette' => '', 'cle' => $cle];
}

/**
 * La clé littérale dont le jeton en position `$i` est la valeur
 * (`'sonde' => …`), vide s'il n'en suit aucune.
 *
 * @param  list<PhpToken>  $jetons
 */
function appelantsCleLitteraleAvant(array $jetons, int $i): string
{
    $fleche = $jetons[$i - 1] ?? null;
    $cle = $jetons[$i - 2] ?? null;

    return $fleche !== null && $fleche->is(T_DOUBLE_ARROW) && $cle !== null && $cle->is(T_CONSTANT_ENCAPSED_STRING)
        ? substr($cle->text, 1, -1)
        : '';
}

/**
 * Vrai si cet appel n'en est pas un : un tableau ou le corps d'un `match`
 * (`[`), un bloc (`{`) ou le corps d'une fonction nommée (`function nom`).
 */
function appelantsEstUnBloc(string $appel): bool
{
    return in_array($appel, ['[', '{'], true) || str_starts_with($appel, 'function ');
}

/**
 * Vrai si la chaîne littérale en position `$i` forme à elle seule un argument
 * de l'appel ouvert, positionnel ou nommé (`group: 'admin'`).
 *
 * @param  list<PhpToken>  $jetons
 */
function appelantsArgumentLitteral(array $jetons, int $i): bool
{
    $avant = $jetons[$i - 1]->text ?? '';
    $nomme = $avant === ':'
        && ($jetons[$i - 2] ?? null)?->is(T_STRING) === true
        && in_array($jetons[$i - 3]->text ?? '', ['(', ','], true);

    return in_array($jetons[$i + 1]->text ?? '', [',', ')'], true)
        && (in_array($avant, ['(', ','], true) || $nomme);
}

/**
 * Les noms de classe et les chaînes qu'un source emploie dans son code, les
 * noms résolus, avec ce qui les entoure : le jeton d'avant (`new`), les deux
 * d'après (`::`, `dispatch` ou `class`), l'appel dont ils sont directement
 * l'argument (`appel`, vide hors de tout appel, `{` dans un bloc ou le corps
 * d'une fonction anonyme, `autre` dans une fonction fléchée, `function x`
 * directement dans le corps de la fonction ou de la méthode nommée `x`, `new x`
 * pour un constructeur, `#x` pour un attribut), cet
 * argument (`argument` : son nom quand il est nommé, sinon son rang compté
 * depuis 0, tableaux traversés), s'ils sont la clé d'un élément de tableau
 * (`cle`, suivis de `=>`), la clé littérale de l'élément dont ils sont la
 * valeur ou dans le tableau duquel ils sont rangés (`cleDuTableau` :
 * `middleware` pour `'middleware' => ['web']`), et les arguments de cet appel
 * faits d'une seule chaîne littérale (`litteraux`, par rang ou par nom). Une
 * chaîne littérale porte sa valeur (`chaine`), et compte aussi comme un nom
 * quand elle en a la forme ; sinon son `nom` est vide.
 *
 * @return list<array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}>
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
    $appelsDesEmplois = [];
    $nombre = count($jetons);

    /**
     * Une case par parenthèse, crochet ou accolade ouverts : son numéro, l'appel
     * ouvert, `[` pour un tableau ou le corps d'un `match` (transparents), `{`
     * pour un bloc et `function nom` pour le corps d'une fonction nommée
     * (opaques), si une fonction fléchée a commencé à ce niveau
     * depuis la dernière virgule (elle court jusqu'à la virgule ou à la
     * fermeture), le rang de l'argument courant, son nom quand il est nommé
     * (`nom:`), et pour un tableau la clé littérale dont il est la valeur.
     *
     * @var list<array{id: int, appel: string, flechee: bool, rang: int, etiquette: string, cle: string}> $pile
     */
    $pile = [];
    $cases = 0;

    /**
     * Par case, ses arguments faits d'une seule chaîne littérale.
     *
     * @var array<int, array<int|string, string>> $litteraux
     */
    $litteraux = [];
    $dernierAppelFerme = '';

    /**
     * Les cases ouvertes par `#[` : une parenthèse qui s'y ouvre derrière un
     * nom pose un attribut.
     *
     * @var array<int, true> $attributs
     */
    $attributs = [];

    /**
     * La fonction nommée dont le corps est attendu, avec la hauteur de la pile à
     * sa déclaration : la première accolade ouverte à cette hauteur ouvre son
     * corps, un `;` à cette hauteur la dit abstraite.
     *
     * @var array{nom: string, hauteur: int}|null $fonction
     */
    $fonction = null;

    for ($i = 0; $i < $nombre; $i++) {
        $jeton = $jetons[$i];

        if ($jeton->is(T_ARRAY) && ($jetons[$i + 1]->text ?? '') === '(') {
            $pile[] = appelantsCase($cases++, '[', appelantsCleLitteraleAvant($jetons, $i));
            $i++;

            continue;
        }

        if ($jeton->text === '{' || $jeton->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $profondeur++;
            $corpsDeMatch = $jeton->text === '{' && ($jetons[$i - 1]->text ?? '') === ')' && $dernierAppelFerme === 'match';
            $appelDuBloc = $corpsDeMatch ? '[' : '{';

            if ($jeton->text === '{' && $fonction !== null && $fonction['hauteur'] === count($pile)) {
                $appelDuBloc = 'function '.$fonction['nom'];
                $fonction = null;
            }

            $pile[] = appelantsCase($cases++, $appelDuBloc);
        } elseif ($jeton->text === '}') {
            $profondeur--;
            array_pop($pile);
        } elseif ($jeton->text === '(') {
            $dansUnAttribut = $pile !== [] && isset($attributs[array_last($pile)['id']]);
            $pile[] = appelantsCase($cases++, appelantsAppelOuvertEn($jetons, $i, $espace, $imports, $dansUnAttribut));
        } elseif ($jeton->text === '[' || $jeton->is(T_ATTRIBUTE)) {
            if ($jeton->is(T_ATTRIBUTE)) {
                $attributs[$cases] = true;
            }

            $pile[] = appelantsCase($cases++, '[', $jeton->text === '[' ? appelantsCleLitteraleAvant($jetons, $i) : '');
        } elseif ($jeton->text === ')' || $jeton->text === ']') {
            $dernierAppelFerme = array_pop($pile)['appel'] ?? '';
        } elseif ($jeton->is(T_FN) && $pile !== []) {
            $sommet = array_key_last($pile);
            $pile[$sommet] = [...$pile[$sommet], 'flechee' => true];
        } elseif ($jeton->text === ',' && $pile !== []) {
            $sommet = array_key_last($pile);
            $pile[$sommet] = [...$pile[$sommet], 'flechee' => false, 'rang' => $pile[$sommet]['rang'] + 1, 'etiquette' => ''];
        } elseif ($jeton->is(T_FUNCTION)) {
            $suivant = $jetons[$i + 1] ?? null;
            $suivant = $suivant?->text === '&' ? ($jetons[$i + 2] ?? null) : $suivant;
            $fonction = $suivant !== null && $suivant->is(T_STRING) ? ['nom' => strtolower($suivant->text), 'hauteur' => count($pile)] : $fonction;
        } elseif ($jeton->text === ';' && $fonction !== null && $fonction['hauteur'] === count($pile)) {
            $fonction = null;
        } elseif (
            $jeton->is(T_STRING) && $pile !== []
            && in_array($jetons[$i - 1]->text ?? '', ['(', ','], true)
            && ($jetons[$i + 1]->text ?? '') === ':'
            && ! appelantsEstUnBloc(array_last($pile)['appel'])
        ) {
            $sommet = array_key_last($pile);
            $pile[$sommet] = [...$pile[$sommet], 'etiquette' => strtolower($jeton->text)];

            continue;
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

        $chaine = $jeton->is(T_CONSTANT_ENCAPSED_STRING);
        $nom = null;

        if ($jeton->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $nom = appelantsNomResolu($jeton, $espace, $imports);
        } elseif ($chaine && preg_match('/^([\'"])\\\\{0,2}(App(?:\\\\{1,2}\w+)+)\1$/', $jeton->text, $classe) === 1) {
            $nom = str_replace('\\\\', '\\', $classe[2]);
        }

        if ($chaine && $pile !== [] && ! appelantsEstUnBloc(array_last($pile)['appel']) && appelantsArgumentLitteral($jetons, $i)) {
            $sommet = array_last($pile);
            $litteraux[$sommet['id']][$sommet['etiquette'] !== '' ? $sommet['etiquette'] : $sommet['rang']] = substr($jeton->text, 1, -1);
        }

        if ($nom !== null || $chaine) {
            $englobant = appelantsAppelEnglobant($pile);
            $sommet = $pile === [] ? null : array_last($pile);
            $cleDirecte = appelantsCleLitteraleAvant($jetons, $i);
            $appelsDesEmplois[] = $englobant['id'];
            $emplois[] = [
                'nom' => strtolower($nom ?? ''),
                'chaine' => $chaine ? substr($jeton->text, 1, -1) : '',
                'avant' => strtolower($jetons[$i - 1]->text ?? ''),
                'apres' => $chaine ? ($nom === null ? '' : '::') : ($jetons[$i + 1]->text ?? ''),
                'membre' => $chaine ? ($nom === null ? '' : 'class') : strtolower($jetons[$i + 2]->text ?? ''),
                'appel' => $englobant['appel'],
                'argument' => $englobant['argument'],
                'cle' => ($jetons[$chaine ? $i + 1 : $i + 3] ?? null)?->is(T_DOUBLE_ARROW) ?? false,
                'cleDuTableau' => $cleDirecte !== '' || $sommet === null || $sommet['appel'] !== '[' ? $cleDirecte : $sommet['cle'],
            ];
        }
    }

    return array_map(
        static fn (array $emploi, int $appel): array => [...$emploi, 'litteraux' => $litteraux[$appel] ?? []],
        $emplois,
        $appelsDesEmplois,
    );
}

/**
 * L'appel dont la position courante est directement l'argument, cet argument
 * et le numéro de sa case : la case la plus haute de la pile qui n'est pas un
 * tableau ; `autre` si une fonction fléchée s'interpose.
 *
 * @param  list<array{id: int, appel: string, flechee: bool, rang: int, etiquette: string, cle: string}>  $pile
 * @return array{appel: string, argument: string, id: int}
 */
function appelantsAppelEnglobant(array $pile): array
{
    foreach (array_reverse($pile) as $case) {
        if ($case['flechee']) {
            return ['appel' => 'autre', 'argument' => '', 'id' => -1];
        }

        if ($case['appel'] !== '[') {
            return [
                'appel' => $case['appel'],
                'argument' => $case['etiquette'] !== '' ? $case['etiquette'] : (string) $case['rang'],
                'id' => $case['id'],
            ];
        }
    }

    return ['appel' => '', 'argument' => '', 'id' => -1];
}

/**
 * Vrai si cet emploi d'un job l'envoie : `Job::dispatch…()`, `Job::withChain()`,
 * ou `new Job` / `Job::class` passé directement à un appel qui envoie.
 *
 * @param  array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}  $emploi
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
 * Le nom de la méthode qu'appelle cet appel (`middleware` pour `->middleware`
 * ou `route::middleware`), ou de l'attribut qu'il pose (`#middleware`) ; vide
 * pour une fonction, un constructeur, un bloc ou le corps d'une fonction.
 */
function appelantsMethodeDe(string $appel): string
{
    return preg_match('/(?:->|::|#)(\w+)$/', $appel, $trouvee) === 1 ? $trouvee[1] : '';
}

/**
 * Vrai si un appel ou une fonction de ce nom branche les middlewares qu'on lui
 * passe ou qu'elle rend : son nom finit par `middleware` (`middleware`,
 * `authmiddleware`, `pushmiddleware`, `getmiddleware`), hors
 * `withoutmiddleware` et `aliasmiddleware`.
 */
function appelantsNomBrancheDesMiddlewares(string $nom): bool
{
    return str_ends_with($nom, 'middleware') && ! in_array($nom, ['withoutmiddleware', 'aliasmiddleware'], true);
}

/**
 * Ce que fait d'un middleware l'appel dont cet emploi est l'argument : `aucun`
 * quand aucune requête ne le traverse (il le retire, le classe parmi les
 * autres, ou ne fait que le nommer : variable, constante, `singleton()`…),
 * `direct` quand il le branche, `alias` quand il lui donne un nom, `groupe`
 * quand il le range dans un groupe, avec pour ces deux derniers l'alias ou le
 * groupe (vide s'il n'est pas écrit en littéral).
 *
 * @param  array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}  $emploi
 * @return array{0: 'aucun'|'direct'|'alias'|'groupe', 1: string}
 */
function appelantsRoleDuMiddleware(array $emploi): array
{
    $methode = appelantsMethodeDe($emploi['appel']);
    $argument = $emploi['argument'];
    $nomLitteral = $emploi['litteraux'][0] ?? $emploi['litteraux']['group'] ?? $emploi['litteraux']['name'] ?? '';
    $declareUnGroupe = in_array($methode, ['appendtogroup', 'prependtogroup', 'middlewaregroup', 'pushmiddlewaretogroup', 'prependmiddlewaretogroup'], true)
        || ($methode === 'group' && $nomLitteral !== '');

    return match (true) {
        in_array($methode, ['withoutmiddleware', 'withoutmiddlewarefor', 'remove', 'removefromgroup', 'priority', 'prependtoprioritylist', 'appendtoprioritylist'], true) => ['aucun', ''],
        $methode === 'replace' => in_array($argument, ['0', 'search'], true) ? ['aucun', ''] : ['direct', ''],
        $methode === 'replaceingroup' => in_array($argument, ['2', 'replace'], true) ? ['groupe', $nomLitteral] : ['aucun', ''],
        in_array($methode, ['web', 'api'], true) => $argument === 'remove' || ($argument === 'replace' && $emploi['cle']) ? ['aucun', ''] : ['groupe', $methode],
        $methode === 'alias' => ['alias', $emploi['cleDuTableau']],
        $methode === 'aliasmiddleware' => in_array($argument, ['1', 'class'], true) ? ['alias', $nomLitteral] : ['aucun', ''],
        $declareUnGroupe => in_array($argument, ['1', 'middleware'], true) ? ['groupe', $nomLitteral] : ['aucun', ''],
        in_array($methode, ['append', 'prepend', 'use'], true), appelantsAppliqueUnMiddleware($emploi) => ['direct', ''],
        default => ['aucun', ''],
    };
}

/**
 * Vrai si cet emploi applique le middleware, l'alias ou le groupe qu'il nomme :
 * passé à un appel dont le nom finit par `middleware` (`->middleware()`,
 * `Route::middleware()`, `->authMiddleware()` d'un panneau), hors
 * `withoutMiddleware()` et `aliasMiddleware()` ; premier argument de
 * `new Middleware()` ou de l'attribut `#[Middleware]` d'un contrôleur ; rangé
 * sous la clé `middleware` d'un tableau (configuration d'un paquet, attributs
 * de `Route::group()`) ; ou écrit directement dans le corps d'une fonction
 * dont le nom finit par `middleware` (`HasMiddleware::middleware()` d'un
 * contrôleur, `getMiddleware()` qu'un fournisseur passe à `->middleware()`).
 *
 * @param  array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}  $emploi
 */
function appelantsAppliqueUnMiddleware(array $emploi): bool
{
    $appel = $emploi['appel'];

    if (in_array($appel, ['new middleware', '#middleware'], true)) {
        return in_array($emploi['argument'], ['0', 'middleware'], true);
    }

    return appelantsNomBrancheDesMiddlewares(appelantsMethodeDe($appel))
        || (str_starts_with($appel, 'function ') && appelantsNomBrancheDesMiddlewares(substr($appel, 9)))
        || $emploi['cleDuTableau'] === 'middleware';
}

/**
 * Les alias et les groupes de middlewares appliqués : `web` et `api`, que
 * Laravel pose sur les fichiers de routes, ceux qu'une chaîne applique,
 * paramètres retirés (`throttle:6,1` applique `throttle`), puis, de proche en
 * proche, ceux que range un groupe appliqué.
 *
 * @param  list<array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}>  $emplois
 * @return array<string, true>
 */
function appelantsAliasEtGroupesAppliques(array $emplois): array
{
    $appliques = ['web' => true, 'api' => true];
    $membres = [];

    foreach ($emplois as $emploi) {
        if ($emploi['nom'] !== '' || $emploi['chaine'] === '' || $emploi['cle']) {
            continue;
        }

        $nom = explode(':', $emploi['chaine'], 2)[0];
        [$role, $groupe] = appelantsRoleDuMiddleware($emploi);

        if ($role === 'groupe') {
            $membres[$groupe][] = $nom;
        } elseif (appelantsAppliqueUnMiddleware($emploi)) {
            $appliques[$nom] = true;
        }
    }

    do {
        $avant = count($appliques);

        foreach ($membres as $groupe => $noms) {
            if (isset($appliques[$groupe])) {
                $appliques += array_fill_keys($noms, true);
            }
        }
    } while (count($appliques) > $avant);

    return $appliques;
}

/**
 * Les middlewares que ces emplois branchent, par nom complet en minuscules :
 * `Middleware::class`, ou son nom complet en chaîne, là où une requête le
 * traverse (`appelantsRoleDuMiddleware()`), ou sous un alias ou dans un groupe
 * appliqués.
 *
 * @param  list<array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}>  $emplois
 * @return array<string, true>
 */
function appelantsMiddlewaresBranchesPar(array $emplois): array
{
    $appliques = appelantsAliasEtGroupesAppliques($emplois);
    $branches = [];

    foreach ($emplois as $emploi) {
        if ($emploi['nom'] === '' || $emploi['apres'] !== '::' || $emploi['membre'] !== 'class') {
            continue;
        }

        [$role, $nom] = appelantsRoleDuMiddleware($emploi);

        if ($role === 'direct' || ($role !== 'aucun' && isset($appliques[$nom]))) {
            $branches[$emploi['nom']] = true;
        }
    }

    return $branches;
}

/**
 * Les classes données qu'aucun fichier ne sollicite comme le demande le test.
 *
 * @param  array<string, string>  $classes  Nom complet => fichier de la classe.
 * @param  list<string>  $fichiers
 * @param  Closure(array{nom: string, chaine: string, avant: string, apres: string, membre: string, appel: string, argument: string, cle: bool, cleDuTableau: string, litteraux: array<int|string, string>}): bool  $sollicite
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
 * Les middlewares de `app/Http/Middleware` que ne branche aucun fichier de
 * `bootstrap/`, `routes/`, `config/`, `app/Providers` ou
 * `app/Http/Controllers`, lus ensemble : un alias déclaré dans
 * `bootstrap/app.php` s'applique dans `routes/`.
 *
 * @return list<string>
 */
function appelantsMiddlewaresNonBranches(): array
{
    $fichiers = appelantsFichiersDe(['bootstrap', 'routes', 'config', 'app/Providers', 'app/Http/Controllers']);
    $branches = appelantsMiddlewaresBranchesPar(array_merge(...array_map(appelantsNomsEmployesDans(...), $fichiers)));

    return array_values(array_filter(
        array_keys(appelantsClassesConcretesDe('Http/Middleware', 'App\\Http\\Middleware')),
        static fn (string $classe): bool => ! isset($branches[strtolower($classe)]),
    ));
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

it('ne compte un middleware comme branché que là où une requête le traverse', function (string $code, bool $branche): void {
    $source = implode("\n", [
        '<?php',
        'namespace App\\Providers;',
        'use App\\Http\\Middleware\\SondeDeLaGarde;',
        'use Illuminate\\Routing\\Attributes\\Controllers\\Middleware as MiddlewareDeControleur;',
        'use Illuminate\\Routing\\Attributes\\Controllers\\WithoutMiddleware;',
        'use Illuminate\\Routing\\Controllers\\Middleware;',
        'use Illuminate\\Support\\Facades\\Route;',
        $code,
    ]);
    $branches = appelantsMiddlewaresBranchesPar(appelantsEmploisDuSource($source));

    expect(isset($branches['app\\http\\middleware\\sondedelagarde']))->toBe($branche);
})->with([
    'withoutMiddleware() d une route' => ["Route::get('/sonde', fn () => 'ok')->withoutMiddleware(SondeDeLaGarde::class);", false],
    'Route::withoutMiddleware() d un groupe' => ['Route::withoutMiddleware([SondeDeLaGarde::class])->group(function () {});', false],
    'withoutMiddlewareFor() d une ressource' => ["Route::resource('sondes', Autre::class)->withoutMiddlewareFor('index', SondeDeLaGarde::class);", false],
    'remove()' => ['$middleware->remove(SondeDeLaGarde::class);', false],
    'remove() d un tableau' => ['$middleware->remove([Autre::class, SondeDeLaGarde::class]);', false],
    'removeFromGroup()' => ["\$middleware->removeFromGroup('web', SondeDeLaGarde::class);", false],
    'web(remove:)' => ['$middleware->web(remove: [SondeDeLaGarde::class]);', false],
    'api(replace:) en clé' => ['$middleware->api(replace: [SondeDeLaGarde::class => Autre::class]);', false],
    'replace() cherché' => ['$middleware->replace(SondeDeLaGarde::class, Autre::class);', false],
    'replace(search:) cherché par son nom' => ['$middleware->replace(replace: Autre::class, search: SondeDeLaGarde::class);', false],
    'replaceInGroup() cherché' => ["\$middleware->replaceInGroup('web', SondeDeLaGarde::class, Autre::class);", false],
    'priority()' => ['$middleware->priority([Autre::class, SondeDeLaGarde::class]);', false],
    'prependToPriorityList()' => ['$middleware->prependToPriorityList(Autre::class, SondeDeLaGarde::class);', false],
    'appendToPriorityList()' => ['$middleware->appendToPriorityList(Autre::class, SondeDeLaGarde::class);', false],
    'branchement en commentaire' => ['// $middleware->append(SondeDeLaGarde::class);', false],
    'méthode statique appelée sans ::class' => ['SondeDeLaGarde::admet($requete);', false],
    'alias() jamais appliqué' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]);", false],
    'alias() par le nom complet en chaîne, jamais appliqué' => ["\$middleware->alias(['sonde' => 'App\\Http\\Middleware\\SondeDeLaGarde']);", false],
    'alias() seulement retiré d une route' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::get('/sonde', fn () => 'ok')->withoutMiddleware('sonde');", false],
    'alias() nommé ailleurs que dans un middleware' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::get('/sonde', fn () => 'ok')->name('sonde');", false],
    'alias() rangé dans un groupe jamais appliqué' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); \$middleware->appendToGroup('admin', 'sonde');", false],
    'aliasMiddleware() d un routeur, jamais appliqué' => ["\$router->aliasMiddleware('sonde', SondeDeLaGarde::class);", false],
    'appendToGroup() d un groupe jamais appliqué' => ["\$middleware->appendToGroup('admin', SondeDeLaGarde::class);", false],
    'prependToGroup() d un groupe jamais appliqué' => ["\$middleware->prependToGroup('admin', [SondeDeLaGarde::class]);", false],
    'group() jamais appliqué' => ["\$middleware->group('admin', [SondeDeLaGarde::class]);", false],
    'group() aux arguments nommés, jamais appliqué' => ["\$middleware->group(middleware: [SondeDeLaGarde::class], group: 'admin');", false],
    'replaceInGroup() remplaçant dans un groupe jamais appliqué' => ["\$middleware->replaceInGroup('admin', Autre::class, SondeDeLaGarde::class);", false],
    'middlewareGroup() d un routeur, jamais appliqué' => ["\$router->middlewareGroup('admin', [SondeDeLaGarde::class]);", false],
    'pushMiddlewareToGroup() d un routeur, jamais appliqué' => ["\$router->pushMiddlewareToGroup('admin', SondeDeLaGarde::class);", false],
    'alias() par une variable, jamais appliqué' => ["\$aliasDeLaSonde = ['sonde' => SondeDeLaGarde::class]; \$middleware->alias(\$aliasDeLaSonde);", false],
    'alias() par une variable, même appliqué' => ["\$aliasDeLaSonde = ['sonde' => SondeDeLaGarde::class]; \$middleware->alias(\$aliasDeLaSonde); Route::middleware('sonde')->group(function () {});", false],
    'alias() par une constante de classe, jamais appliqué' => ["final class P { private const ALIAS = ['sonde' => SondeDeLaGarde::class]; public function boot(): void { \$this->middleware->alias(self::ALIAS); } }", false],
    'alias() par array_merge(), jamais appliqué' => ["\$middleware->alias(array_merge(\$autres, ['sonde' => SondeDeLaGarde::class]));", false],
    'attribut WithoutMiddleware d un contrôleur' => ['#[WithoutMiddleware(SondeDeLaGarde::class)] final class C {}', false],
    'attribut WithoutMiddleware retirant un alias' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); #[WithoutMiddleware('sonde')] final class C {}", false],
    'attribut Middleware citant un alias hors de son premier argument' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); #[MiddlewareDeControleur('auth', only: ['sonde'])] final class C {}", false],
    'singleton() d un fournisseur' => ['$this->app->singleton(SondeDeLaGarde::class);', false],
    'liste rendue par une fonction qui ne branche rien' => ['function autresClasses(): array { return [Autre::class, SondeDeLaGarde::class]; }', false],
    'append()' => ['$middleware->append(SondeDeLaGarde::class);', true],
    'prepend()' => ['$middleware->prepend(SondeDeLaGarde::class);', true],
    'append() d un ternaire' => ['$middleware->append($actif ? SondeDeLaGarde::class : Autre::class);', true],
    'appendToGroup() de web' => ["\$middleware->appendToGroup('web', SondeDeLaGarde::class);", true],
    'web(append:)' => ['$middleware->web(append: [SondeDeLaGarde::class]);', true],
    'web(append:) après un retrait' => ['$middleware->web(remove: [Autre::class], append: [SondeDeLaGarde::class]);', true],
    'web(replace:) en valeur' => ['$middleware->web(replace: [Autre::class => SondeDeLaGarde::class]);', true],
    'replace() remplaçant' => ['$middleware->replace(Autre::class, SondeDeLaGarde::class);', true],
    'replace(replace:) remplaçant par son nom' => ['$middleware->replace(search: Autre::class, replace: SondeDeLaGarde::class);', true],
    'replaceInGroup() remplaçant' => ["\$middleware->replaceInGroup('web', Autre::class, SondeDeLaGarde::class);", true],
    'middleware() d une route' => ["Route::get('/sonde', fn () => 'ok')->middleware([SondeDeLaGarde::class]);", true],
    'Route::middleware() d un groupe' => ['Route::middleware(SondeDeLaGarde::class)->group(function () {});', true],
    'middleware d un panneau' => ['$panel->authMiddleware([SondeDeLaGarde::class]);', true],
    'tableau de configuration' => ["return ['middleware' => ['web', SondeDeLaGarde::class]];", true],
    'tableau de configuration par le nom complet en chaîne' => ["return ['middleware' => ['App\\Http\\Middleware\\SondeDeLaGarde']];", true],
    'middleware d un contrôleur' => ["return [new Middleware(SondeDeLaGarde::class, except: ['index'])];", true],
    'attribut Middleware d un contrôleur' => ['#[MiddlewareDeControleur(SondeDeLaGarde::class)] final class C {}', true],
    'HasMiddleware::middleware() d un contrôleur' => ["final class C { public static function middleware(): array { return ['auth', SondeDeLaGarde::class]; } }", true],
    'liste rendue par getMiddleware() et passée à un panneau' => ['final class P { public function panel($panel) { return $panel->middleware($this->getMiddleware()); } private function getMiddleware(): array { return [Autre::class, SondeDeLaGarde::class]; } }', true],
    'alias() appliqué par une route' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::get('/sonde', fn () => 'ok')->middleware('sonde');", true],
    'alias() appliqué avec ses paramètres' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::middleware(['auth', 'sonde:6,1'])->group(function () {});", true],
    'alias() appliqué dans un ternaire' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::middleware(\$enProduction ? 'sonde:60,1' : 'sonde:1000,1')->group(function () {});", true],
    'alias() appliqué par la configuration' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); return ['middleware' => ['web', 'sonde']];", true],
    'alias() appliqué par les attributs de Route::group()' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); Route::group(['middleware' => 'sonde'], function () {});", true],
    'alias() appliqué par un contrôleur' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); return [new Middleware('sonde', only: ['index'])];", true],
    'alias() appliqué par l attribut Middleware d un contrôleur' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); #[MiddlewareDeControleur('sonde')] final class C {}", true],
    'alias() appliqué par un attribut Middleware groupé' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); final class C { #[MiddlewareDeControleur('auth'), MiddlewareDeControleur('sonde:6,1', only: ['index'])] public function index() {} }", true],
    'alias() appliqué par HasMiddleware::middleware() d un contrôleur' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); final class C { public static function middleware(): array { return ['auth', 'sonde']; } }", true],
    'alias() appliqué par un panneau' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); \$panel->authMiddleware(['sonde']);", true],
    'alias() rangé dans web' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); \$middleware->web(append: ['sonde']);", true],
    'alias() rangé dans un groupe appliqué' => ["\$middleware->alias(['sonde' => SondeDeLaGarde::class]); \$middleware->appendToGroup('admin', 'sonde'); Route::middleware('admin')->group(function () {});", true],
    'aliasMiddleware() d un routeur, appliqué' => ["\$router->aliasMiddleware('sonde', SondeDeLaGarde::class); Route::middleware('sonde')->group(function () {});", true],
    'appendToGroup() d un groupe appliqué' => ["\$middleware->appendToGroup('admin', SondeDeLaGarde::class); Route::middleware('admin')->group(function () {});", true],
    'group() appliqué parmi d autres' => ["\$middleware->group('admin', [SondeDeLaGarde::class]); Route::get('/sonde', fn () => 'ok')->middleware(['auth', 'admin']);", true],
    'group() aux arguments nommés, appliqué' => ["\$middleware->group(middleware: [SondeDeLaGarde::class], group: 'admin'); Route::middleware('admin')->group(function () {});", true],
    'groupe rangé dans un groupe appliqué' => ["\$middleware->group('interne', [SondeDeLaGarde::class]); \$middleware->appendToGroup('admin', 'interne'); Route::middleware('admin')->group(function () {});", true],
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
        'Ces middlewares ne sont branchés par aucun fichier de bootstrap/, routes/, config/, app/Providers ou app/Http/Controllers :',
        '  '.implode("\n  ", $orphelins),
        '',
        'Le détecteur de code mort tient leur handle() pour utilisé parce qu\'il reçoit une Request (#1991).',
        "La garde ne lit que les branchements écrits en littéral aux places que liste l'en-tête de ce fichier : un nom, un alias ou un groupe tenus par une variable, une constante ou array_merge() ne comptent pas.",
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
