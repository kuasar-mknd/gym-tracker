<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Les actifs que la CI teste sont ceux que l'image sert (#1988).
 *
 * La CI construit les actifs depuis le dépôt entier, et les tests PHP comme les
 * éclats Dusk reçoivent ce résultat. L'image les construit dans l'étape
 * `frontend-builder` du Dockerfile, depuis les seuls chemins que cette étape
 * copie. Tant que Tailwind détectait seul ses sources, il lisait tout fichier
 * que git n'ignore pas sous la racine : les tests, les migrations, `app/` ou
 * `.ai/rules` faisaient émettre à la CI 45 sélecteurs absents de l'image, et
 * Dusk validait une feuille de style qui ne partait pas en production.
 *
 * L'étape « L'image construit les mêmes actifs » de `ci.yml` reconstruit le
 * contexte de l'image et compare les deux `public/build` octet pour octet.
 * Cette garde dit plus tôt, sans rien construire, ce qui les ferait diverger :
 *
 * - un `@import 'tailwindcss'` sans `source(none)` rend la détection
 *   automatique ;
 * - une `@source` hors des chemins que l'étape copie lit, dans la CI, ce que
 *   l'image n'a pas ;
 * - une `@source` qui n'existe pas ne lit rien, ni dans la CI ni dans l'image ;
 * - un chemin que `.gitignore` masque sous une source est lu par l'image, qui
 *   n'a ni `.git` ni `.gitignore`, et pas par la CI : il s'écarte par
 *   `@source not`.
 *
 * Seuls les motifs ancrés de `.gitignore` (`/resources/…`) sont lus : un motif
 * flottant (`*.log`, `.env`) qui viserait un fichier suivi sous `resources/`
 * échappe à la garde, et l'étape de la CI le verrait.
 */

/**
 * Les chemins que l'étape `frontend-builder` du Dockerfile copie, relatifs à la
 * racine du dépôt, sans barre finale.
 *
 * @return list<string>
 */
function actifsCheminsCopiesParLImage(): array
{
    $lignes = file(base_path('Dockerfile'), FILE_IGNORE_NEW_LINES);
    $dansLEtape = false;
    $chemins = [];

    foreach ($lignes === false ? [] : $lignes as $ligne) {
        if (preg_match('/^FROM\s/i', $ligne) === 1) {
            $dansLEtape = preg_match('/\sAS\s+frontend-builder\s*$/i', $ligne) === 1;

            continue;
        }

        if (! $dansLEtape || preg_match('/^COPY\s+(.+)$/i', $ligne, $copie) !== 1) {
            continue;
        }

        $arguments = preg_split('/\s+/', trim($copie[1]));
        $arguments = $arguments === false ? [] : $arguments;
        array_pop($arguments);

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                $chemins[] = rtrim($argument, '/');
            }
        }
    }

    return $chemins;
}

/**
 * Les directives de `resources/css/app.css` qui décident de ce que Tailwind lit,
 * commentaires écartés. Les sources sont ramenées à la racine du dépôt.
 *
 * @return array{imports: list<string>, sources: list<string>, exclusions: list<string>}
 */
function actifsDirectivesDeLaFeuille(): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $sansCommentaires = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

    preg_match_all('/@import\s+([\'"])tailwindcss\1([^;]*);/', $sansCommentaires, $imports);
    preg_match_all('/@source\s+(not\s+)?([\'"])(.+?)\2\s*;/', $sansCommentaires, $sources, PREG_SET_ORDER);

    $directives = ['imports' => array_map(trim(...), $imports[2]), 'sources' => [], 'exclusions' => []];

    foreach ($sources as $source) {
        $directives[$source[1] === '' ? 'sources' : 'exclusions'][] = actifsCheminDepuisLaRacine('resources/css/'.$source[3]);
    }

    return $directives;
}

/**
 * Résout les `.` et `..` d'un chemin relatif à la racine, sans exiger qu'il existe.
 */
function actifsCheminDepuisLaRacine(string $chemin): string
{
    $segments = [];

    foreach (explode('/', $chemin) as $segment) {
        if ($segment === '..') {
            array_pop($segments);
        } elseif ($segment !== '' && $segment !== '.') {
            $segments[] = $segment;
        }
    }

    return implode('/', $segments);
}

/**
 * La partie d'un chemin qui précède son premier motif (`*`, `?`, `[`, `{`).
 */
function actifsPrefixeSansMotif(string $chemin): string
{
    if (preg_match('/[*?\[{]/', $chemin, $motif, PREG_OFFSET_CAPTURE) !== 1) {
        return $chemin;
    }

    $avant = substr($chemin, 0, $motif[0][1]);
    $derniereBarre = strrpos($avant, '/');

    return $derniereBarre === false ? '' : substr($avant, 0, $derniereBarre);
}

/**
 * Le chemin est-il égal à l'un des parents donnés, ou en dessous ?
 *
 * Un parent qui porte un motif (`package*.json`) se compare entier, par fnmatch.
 *
 * @param  list<string>  $parents
 */
function actifsEstSousLUnDe(string $chemin, array $parents): bool
{
    return array_any($parents, fn (string $parent): bool => $chemin === $parent || str_starts_with($chemin, $parent.'/') || fnmatch($parent, $chemin));
}

it('coupe la détection automatique de Tailwind', function (): void {
    $imports = actifsDirectivesDeLaFeuille()['imports'];

    expect($imports)->toHaveCount(1, "`resources/css/app.css` doit importer Tailwind une fois, par `@import 'tailwindcss' source(none);`.")
        ->and(str_contains($imports[0] ?? '', 'source(none)'))->toBeTrue(implode("\n", [
            "`@import 'tailwindcss'` a perdu `source(none)` : Tailwind lit de nouveau tout le dépôt.",
            "La CI construit depuis le dépôt entier et l'image depuis `resources/` seulement :",
            'les deux feuilles divergent, et Dusk teste des classes que la production n\'a pas (#1988).',
            'Nommer les sources par `@source`, sous les chemins que copie l\'étape `frontend-builder` du Dockerfile.',
        ]));
});

it("ne lit que des chemins que l'image copie", function (): void {
    $copies = actifsCheminsCopiesParLImage();
    $sources = actifsDirectivesDeLaFeuille()['sources'];

    expect(in_array('resources', $copies, true))->toBeTrue("L'étape `frontend-builder` du Dockerfile ne copie plus `resources/` : la garde ne sait plus ce que l'image lit.")
        ->and($sources)->not->toBeEmpty('`resources/css/app.css` ne déclare aucune `@source` : Tailwind ne lirait aucune classe.');

    $horsDeLImage = array_values(array_filter(
        $sources,
        static fn (string $source): bool => ! actifsEstSousLUnDe(actifsPrefixeSansMotif($source), $copies),
    ));

    expect($horsDeLImage)->toBe([], sprintf(
        "Ces sources de `resources/css/app.css` ne sont pas dans le contexte de l'image (%s) :\n  %s\n\n"
        ."La CI y lirait des classes que l'image n'émet pas. Déplacer le fichier sous `resources/`, ou copier son dossier dans l'étape `frontend-builder` du Dockerfile.",
        implode(', ', $copies),
        implode("\n  ", $horsDeLImage),
    ));
});

it('ne déclare que des sources qui existent', function (): void {
    $absentes = array_values(array_filter(
        actifsDirectivesDeLaFeuille()['sources'],
        static fn (string $source): bool => ! file_exists(base_path(actifsPrefixeSansMotif($source))),
    ));

    expect($absentes)->toBe([], "Ces sources de `resources/css/app.css` n'existent pas, et ne lisent rien :\n  ".implode("\n  ", $absentes));
});

it('écarte ce que git masque sous ses sources', function (): void {
    $directives = actifsDirectivesDeLaFeuille();
    $lignes = file(base_path('.gitignore'), FILE_IGNORE_NEW_LINES);

    $masques = array_values(array_filter(array_map(
        static fn (string $ligne): string => rtrim(ltrim(trim($ligne), '/'), '/'),
        array_filter(
            $lignes === false ? [] : $lignes,
            static fn (string $ligne): bool => str_starts_with(trim($ligne), '/'),
        ),
    ), static fn (string $masque): bool => actifsEstSousLUnDe($masque, $directives['sources'])));

    $nonEcartes = array_values(array_filter(
        $masques,
        static fn (string $masque): bool => ! actifsEstSousLUnDe($masque, $directives['exclusions']),
    ));

    expect($nonEcartes)->toBe([], sprintf(
        "`.gitignore` masque ces chemins sous une source de `resources/css/app.css` :\n  %s\n\n"
        ."Dans le dépôt, Tailwind les saute ; dans le contexte de l'image, sans `.gitignore`, il les lit. Les écarter par `@source not`.",
        implode("\n  ", $nonEcartes),
    ));
});

it("fait comparer à la CI les actifs du dépôt et ceux de l'image", function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $etapes = data_get($workflow, 'jobs.frontend-assets.steps', []);
    $noms = array_map(
        static fn (mixed $etape): string => is_array($etape) && is_string($etape['name'] ?? null) ? $etape['name'] : '',
        is_array($etapes) ? array_values($etapes) : [],
    );

    $comparaison = array_search("L'image construit les mêmes actifs", $noms, true);
    $envoi = array_search('Upload Build Artifacts', $noms, true);

    expect($comparaison)->toBeInt("Le job `frontend-assets` de ci.yml ne compare plus les actifs du dépôt à ceux du contexte de l'image (#1988).")
        ->and($envoi)->toBeInt();

    assert(is_int($comparaison) && is_int($envoi) && is_array($etapes));

    expect($comparaison)->toBeLessThan($envoi, "La comparaison doit précéder l'envoi des actifs aux tests et à Dusk.");
    $script = data_get($etapes, $comparaison.'.run', '');

    expect($script)->toBeString()
        ->toContain('frontend-builder')
        ->toContain('diff -r public/build')
        ->toContain('cmp public/sw.js');
});
