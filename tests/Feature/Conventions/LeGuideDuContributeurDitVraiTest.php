<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/*
 * Ce que lit un contributeur avant sa première ligne de code (#1992).
 *
 * `CONTRIBUTING.md` faisait lancer `./vendor/bin/sail composer setup` juste
 * après le clone : `vendor/` n'est pas versionné, la commande n'existait pas,
 * et Sail ne transmet une commande qu'à des conteneurs démarrés. Ses exemples
 * de commit étaient en anglais, au format `feat: …`. Le formulaire de bug
 * imposait de choisir une version fictive (1.0.2 ou 1.0.3, quand les tags en
 * sont à v1.5), le lien d'aide menait aux Discussions, désactivées sur le
 * dépôt, et `SECURITY.md` annonçait `npm audit`, remplacé par OSV.
 *
 * Cette garde tient :
 *
 * - le renvoi à l'installation du README, vers un titre qui existe, avant la
 *   première commande du guide ;
 * - chaque commande Sail du guide : sa commande Artisan, son script Composer
 *   ou npm, son binaire de `vendor/bin`, le test que vise un `--filter` ;
 * - le format des exemples de commit, `type(portée): constat` ;
 * - un formulaire de bug sans liste de versions ;
 * - des liens d'aide qui ne mènent qu'à des fonctions actives du dépôt ;
 * - les audits de dépendances que `SECURITY.md` annonce, lancés par la CI.
 */

/**
 * Le texte de `CONTRIBUTING.md`.
 */
function guideContenu(): string
{
    return (string) file_get_contents(base_path('CONTRIBUTING.md'));
}

/**
 * L'ancre que GitHub donne à un titre Markdown.
 */
function guideAncreDuTitre(string $titre): string
{
    $texte = mb_strtolower(trim($titre));
    $texte = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $texte);

    return str_replace(' ', '-', $texte);
}

/**
 * Chaque appel à Sail du guide, avec sa position : sous-commande et arguments.
 *
 * @return list<array{position: int, commande: string, arguments: list<string>}>
 */
function guideCommandesSail(): array
{
    preg_match_all('#(?:\./)?vendor/bin/sail[ \t]+([^`\n&]+)#', guideContenu(), $appels, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    return array_map(static function (array $appel): array {
        $mots = preg_split('/\s+/', trim($appel[1][0]));
        $mots = $mots === false ? [] : $mots;

        return ['position' => $appel[0][1], 'commande' => (string) array_shift($mots), 'arguments' => $mots];
    }, $appels);
}

it('renvoie à l installation du README avant toute commande', function (): void {
    $guide = guideContenu();
    $readme = (string) file_get_contents(base_path('README.md'));

    if (preg_match('/\]\(README\.md#([^)]+)\)/', $guide, $lien, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException("CONTRIBUTING.md ne renvoie plus à la section d'installation du README.");
    }

    preg_match_all('/^#{1,6}\s+(.+)$/m', $readme, $titres);
    $ancres = array_map(guideAncreDuTitre(...), $titres[1]);
    $premiere = guideCommandesSail()[0]['position'] ?? PHP_INT_MAX;

    expect($ancres)->toContain($lien[1][0])
        ->and(str_contains($lien[1][0], 'installation'))->toBeTrue('Le premier renvoi de CONTRIBUTING.md au README doit viser son installation.')
        ->and($lien[0][1])->toBeLessThan($premiere, "CONTRIBUTING.md cite une commande Sail avant de renvoyer à l'installation : sur un clone neuf, `vendor/bin/sail` n'existe pas.");
});

it('ne cite que des commandes qui existent', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $npm = json_decode((string) file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
    $scriptsComposer = array_keys(is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : []);
    $scriptsNpm = array_keys(is_array($npm) && is_array($npm['scripts'] ?? null) ? $npm['scripts'] : []);
    $commandesComposer = ['audit', 'dump-autoload', 'install', 'outdated', 'require', 'show', 'update', 'validate'];
    $artisan = array_keys(Artisan::all());
    $inconnues = [];

    expect(guideCommandesSail())->not->toBeEmpty();

    foreach (guideCommandesSail() as ['commande' => $commande, 'arguments' => $arguments]) {
        $premier = $arguments[0] ?? '';
        $appel = trim($commande.' '.implode(' ', $arguments));

        $existe = match ($commande) {
            'artisan' => in_array($premier, $artisan, true),
            'composer' => in_array($premier, [...$scriptsComposer, ...$commandesComposer], true),
            'npm' => $premier !== 'run' || in_array($arguments[1] ?? '', $scriptsNpm, true),
            'bin' => is_file(base_path('vendor/bin/'.$premier)),
            default => true,
        };

        if (! $existe) {
            $inconnues[] = $appel;
        }

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--filter=') && glob(base_path('tests/*/'.substr($argument, 9).'.php')) === []) {
                $inconnues[] = $appel.' (aucun fichier de test de ce nom)';
            }
        }
    }

    expect($inconnues)->toBe([], "CONTRIBUTING.md cite des commandes qui n'existent pas :\n  ".implode("\n  ", $inconnues));
});

it('donne des exemples de commit au format du dépôt', function (): void {
    $guide = guideContenu();
    preg_match_all('/git commit -m "([^"]+)"/', $guide, $messages);
    $exemples = preg_match('/\*\*Exemples :\*\*\s*```\n(.*?)```/s', $guide, $bloc) === 1
        ? array_values(array_filter(array_map(trim(...), explode("\n", $bloc[1])), static fn (string $ligne): bool => $ligne !== ''))
        : [];

    $tous = [...$messages[1], ...$exemples];
    $horsFormat = array_values(array_filter(
        $tous,
        static fn (string $message): bool => preg_match('/^\p{Ll}+\([^)]+\): \S.*\(#\d+\)$/u', $message) !== 1,
    ));

    expect($exemples)->not->toBeEmpty()
        ->and($messages[1])->not->toBeEmpty()
        ->and($horsFormat)->toBe([], "Ces exemples de commit ne suivent pas le format `type(portée): constat (#issue)` :\n  ".implode("\n  ", $horsFormat));
});

it('n impose aucune liste de versions dans le formulaire de bug', function (): void {
    $formulaire = Yaml::parseFile(base_path('.github/ISSUE_TEMPLATE/bug_report.yml'));
    $champs = data_get($formulaire, 'body', []);
    $versionsFigees = [];

    foreach (is_array($champs) ? $champs : [] as $champ) {
        $options = is_array($champ) ? data_get($champ, 'attributes.options', []) : [];

        foreach (is_array($options) ? $options : [] as $option) {
            if (is_string($option) && preg_match('/\bv?\d+\.\d+/', $option) === 1) {
                $versionsFigees[] = $option;
            }
        }
    }

    expect($versionsFigees)->toBe([], "Le formulaire de bug propose une liste de versions, qui vieillit à chaque tag :\n  ".implode("\n  ", $versionsFigees)."\n\nDemander la version en texte libre (tag ou commit).");
});

it('ne mène les liens d aide qu à des fonctions actives du dépôt', function (): void {
    /*
     * Discussions et wiki sont désactivés sur le dépôt (vérifié par l'API le
     * 2026-10-05 : has_discussions et has_wiki à false). Les activer, c'est
     * ajouter leur chemin ici.
     */
    $cheminsActifs = ['', 'issues', 'security/policy', 'security/advisories/new'];
    $configuration = Yaml::parseFile(base_path('.github/ISSUE_TEMPLATE/config.yml'));
    $liens = data_get($configuration, 'contact_links', []);
    $morts = [];

    expect(is_array($liens) && $liens !== [])->toBeTrue('.github/ISSUE_TEMPLATE/config.yml ne propose plus aucun lien.');

    foreach (is_array($liens) ? $liens : [] as $lien) {
        $url = is_array($lien) && is_string($lien['url'] ?? null) ? $lien['url'] : '';
        $hote = parse_url($url, PHP_URL_HOST);
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn (string $segment): bool => $segment !== ''));

        if ($hote !== 'github.com') {
            continue;
        }

        $chemin = implode('/', array_slice($segments, 2));
        $fichierDuDepot = preg_match('#^(?:blob|tree)/main/(.+)$#', $chemin, $fichier) === 1 && file_exists(base_path($fichier[1]));

        if (! in_array($chemin, $cheminsActifs, true) && ! $fichierDuDepot) {
            $morts[] = $url;
        }
    }

    expect($morts)->toBe([], "Ces liens de .github/ISSUE_TEMPLATE/config.yml mènent à une fonction que le dépôt n'a pas :\n  ".implode("\n  ", $morts));
});

it('n annonce dans SECURITY.md que des audits que la CI lance', function (): void {
    $securite = (string) file_get_contents(base_path('SECURITY.md'));
    $ligne = preg_match('/^\|\s*Dépendances\s*\|(.+)\|\s*$/mu', $securite, $trouvee) === 1 ? $trouvee[1] : '';
    preg_match_all('/`([^`]+)`/', $ligne, $outils);

    $etapes = data_get(Yaml::parseFile(base_path('.github/workflows/ci.yml')), 'jobs.*.steps.*.run', []);
    $scripts = implode("\n", array_filter(is_array($etapes) ? $etapes : [], is_string(...)));
    $absents = array_values(array_filter($outils[1], static fn (string $outil): bool => ! str_contains($scripts, $outil)));

    expect($outils[1])->not->toBeEmpty('SECURITY.md ne dit plus quels audits de dépendances tournent.')
        ->and($absents)->toBe([], "SECURITY.md annonce ce que la CI ne lance pas :\n  ".implode("\n  ", $absents));
});
