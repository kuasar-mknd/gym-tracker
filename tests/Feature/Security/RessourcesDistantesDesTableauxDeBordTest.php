<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use App\Support\Csp\RetireLesRessourcesDistantesDesPaquets;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\actingAs;

/**
 * Horizon, Pulse et Telescope chargeaient leur fonte sur un site de polices
 * tiers, et la carte des requêtes sortantes de Pulse l'icône de chaque hôte sur
 * un service d'icônes : chaque page leur envoyait l'adresse IP et le navigateur
 * de l'administrateur. La CSP bloquait la feuille de style, pas la
 * préconnexion. Ces balises sont retirées des gabarits des paquets à leur
 * compilation (`RetireLesRessourcesDistantesDesPaquets`), sans recopier les
 * gabarits.
 */
function ressourcesDistantesPrecompile(string $cheminRelatif, string $gabarit): string
{
    Blade::setPath(base_path($cheminRelatif));

    return new RetireLesRessourcesDistantesDesPaquets()($gabarit);
}

/**
 * Le HTML, chaque script et chaque style vidé de son contenu, et le contenu de
 * chaque style à part : Pulse recopie livewire.js dans sa page, Horizon tout
 * son app.js, et le texte d'un script n'est ni une balise ni une feuille de
 * style. Parcours linéaire : une expression paresseuse sur le mégaoctet
 * d'app.js dépasse la limite de retour arrière de PCRE, et `preg_replace()`
 * rendrait alors une page vide, sans ressource à trouver.
 *
 * @return array{html: string, feuilles: list<string>}
 */
function ressourcesDistantesDecoupe(string $html): array
{
    $sortie = '';
    $feuilles = [];
    $position = 0;

    while (preg_match('/<(script|style)\b[^>]*>/i', $html, $ouverture, PREG_OFFSET_CAPTURE, $position) === 1) {
        $finDeLOuverture = $ouverture[0][1] + strlen($ouverture[0][0]);
        $sortie .= substr($html, $position, $finDeLOuverture - $position);
        $fermeture = stripos($html, '</'.$ouverture[1][0], $finDeLOuverture);
        $position = $fermeture === false ? strlen($html) : $fermeture;

        if (strtolower($ouverture[1][0]) === 'style') {
            $feuilles[] = substr($html, $finDeLOuverture, $position - $finDeLOuverture);
        }
    }

    return ['html' => $sortie.substr($html, $position), 'feuilles' => $feuilles];
}

/**
 * Une adresse absolue ou relative au protocole qui ne mène pas à l'origine de
 * l'application (`APP_URL`, d'où Livewire sert son script). Un gabarit d'un
 * paquet ne connaît pas cette origine : toute adresse absolue y est d'une
 * autre.
 */
function ressourcesDistantesAutreOrigine(string $adresse): bool
{
    return ! str_starts_with($adresse.'/', rtrim(config()->string('app.url'), '/').'/');
}

/**
 * Ce qu'une feuille de style ferait charger d'une autre origine : chaque
 * `url(…)` et chaque `@import` dont l'adresse est absolue ou relative au
 * protocole. Une image en `data:`, l'espace de noms XML qu'elle porte ou une
 * adresse dans un commentaire n'y comptent pas : ils ne suivent ni `url(` ni
 * `@import`.
 *
 * @return list<string>
 */
function ressourcesDistantesDansUneFeuille(string $css): array
{
    if (preg_match_all('/(?:\burl\(|@import\b)\s*["\']?\s*((?:https?:)?\/\/[^"\'\s)]*)/i', $css, $appels, PREG_SET_ORDER) === false) {
        throw new RuntimeException('Le balayage de la feuille de style a échoué : '.preg_last_error_msg());
    }

    return array_values(array_map(
        static fn (array $appel): string => $appel[0],
        array_filter($appels, static fn (array $appel): bool => ressourcesDistantesAutreOrigine($appel[1])),
    ));
}

/**
 * Ce qu'un HTML ferait charger d'une autre origine : chaque balise, hors des
 * liens `<a>`, que le navigateur ne suit qu'au clic, dont un `src`, un `href`
 * ou un `srcset` mène à une autre origine, ou dont l'attribut `style` y fait
 * charger quelque chose ; puis chaque `url(…)` ou `@import` d'une autre origine
 * dans un `<style>`, précédé de `<style>`.
 *
 * @return list<string>
 */
function ressourcesDistantesDans(string $html): array
{
    $decoupe = ressourcesDistantesDecoupe($html);

    if (preg_match_all('/<(?!a\b)[a-z][a-z0-9-]*\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $decoupe['html'], $balises) === false) {
        throw new RuntimeException('Le balayage des balises a échoué : '.preg_last_error_msg());
    }

    $distantes = array_values(array_filter($balises[0], static function (string $balise): bool {
        preg_match_all('/\s(?:src|href|srcset)\s*=\s*["\']?\s*((?:https?:)?\/\/[^"\'\s>]*)/i', $balise, $adresses);
        preg_match_all('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $balise, $styles);

        return array_any($adresses[1], ressourcesDistantesAutreOrigine(...))
            || array_any([...$styles[1], ...$styles[2]], static fn (string $style): bool => ressourcesDistantesDansUneFeuille($style) !== []);
    }));

    foreach ($decoupe['feuilles'] as $feuille) {
        foreach (ressourcesDistantesDansUneFeuille($feuille) as $appel) {
            $distantes[] = '<style> '.$appel;
        }
    }

    return $distantes;
}

/**
 * Le détecteur lui-même : un motif qui cesserait de mordre rendrait une liste
 * vide sur toute page, et les gardes ci-dessous passeraient pour une raison qui
 * n'aurait rien de rassurant.
 */
it('reconnaît une ressource d’une autre origine, et rien d’autre', function (): void {
    config(['app.url' => 'http://localhost']);

    $html = <<<'HTML'
        <link rel="preconnect" href="https://fonts.example.org">
        <link href="https://fonts.example.org/css?family=figtree:300,400" rel="stylesheet" />
        <img srcset='http://images.example.org/a.png 2x' alt="">
        <script src="//cdn.example.org/a.js"></script>
        <script>const gabarit = '<img src="https://ailleurs.example.org/b.png">';</script>
        <a href="https://docs.example.org">Documentation</a>
        <img src="/build/logo.svg"><link rel="icon" href="data:image/png;base64,AAAA">
        <script src="http://localhost/livewire/livewire.min.js?id=1"></script>
        <img src="http://localhost.example.org/piege.png">
        <svg xmlns="http://www.w3.org/2000/svg"></svg>
        <div style="background-image:url(https://images.example.org/fond.png)"></div>
        <span style='background: url("/build/fond.png")'></span>
        <style>@import url("https://fonts.example.org/css?family=figtree");</style>
        <style>
            /*! Bootstrap (https://getbootstrap.com/) */
            @IMPORT '//fonts.example.org/b.css';
            .a { background: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg'%3e%3c/svg%3e"); }
            .b { background: url(/build/a.png), URL( 'http://localhost/b.png' ); }
            @font-face { font-family: Figtree; src: url(https://fonts.example.org/figtree.woff2) format("woff2"); }
        </style>
        <script>const feuille = '<style>@import url(https://ailleurs.example.org/c.css)</style>';</script>
        HTML;

    expect(ressourcesDistantesDans($html))->toBe([
        '<link rel="preconnect" href="https://fonts.example.org">',
        '<link href="https://fonts.example.org/css?family=figtree:300,400" rel="stylesheet" />',
        "<img srcset='http://images.example.org/a.png 2x' alt=\"\">",
        '<script src="//cdn.example.org/a.js">',
        '<img src="http://localhost.example.org/piege.png">',
        '<div style="background-image:url(https://images.example.org/fond.png)">',
        '<style> url("https://fonts.example.org/css?family=figtree',
        "<style> @IMPORT '//fonts.example.org/b.css",
        '<style> url(https://fonts.example.org/figtree.woff2',
    ])->and(ressourcesDistantesDans('<script>'.str_repeat('a()', 1_000_000).'</script><link href="https://fonts.example.org/a.css" rel="stylesheet">'))
        ->toBe(['<link href="https://fonts.example.org/a.css" rel="stylesheet">']);
});

/**
 * Les feuilles que Horizon, Pulse et Telescope recopient en ligne dans leurs
 * pages (`Horizon::css()`, `Pulse::css()`, `Telescope::css()`), lues dans
 * leurs fichiers : la page de Telescope n'est pas servie dans les tests, et
 * une fonte déplacée dans l'une d'elles échapperait au précompilateur, qui ne
 * retire que des balises.
 */
it('n’a rien d’une autre origine dans les feuilles que Horizon, Pulse et Telescope recopient dans leurs pages', function (): void {
    $feuilles = [];
    $distantes = [];

    foreach (['horizon', 'pulse', 'telescope'] as $paquet) {
        $fichiers = glob(base_path('vendor/laravel/'.$paquet.'/dist/*.css'));

        foreach ($fichiers === false ? [] : $fichiers as $feuille) {
            $feuilles[] = substr($feuille, strlen(base_path()) + 1);

            foreach (ressourcesDistantesDansUneFeuille((string) file_get_contents($feuille)) as $appel) {
                $distantes[] = substr($feuille, strlen(base_path()) + 1).' : '.$appel;
            }
        }
    }

    expect($feuilles)->toContain('vendor/laravel/horizon/dist/app.css', 'vendor/laravel/horizon/dist/styles.css', 'vendor/laravel/pulse/dist/pulse.css')
        ->and($distantes)->toBe([]);
});

/**
 * Chaque gabarit Blade des trois paquets, relatif à la racine du dépôt.
 * Telescope n'est installé qu'en développement : ses gabarits comptent quand
 * ils sont là.
 *
 * @return list<string>
 */
function ressourcesDistantesGabaritsDesTableauxDeBord(): array
{
    $dossiers = array_values(array_filter(
        [base_path('vendor/laravel/horizon/resources/views'), base_path('vendor/laravel/pulse/resources/views'), base_path('vendor/laravel/telescope/resources/views')],
        is_dir(...),
    ));

    $gabarits = [];

    foreach (Finder::create()->files()->in($dossiers)->name('*.blade.php') as $fichier) {
        $gabarits[] = substr($fichier->getPathname(), strlen(base_path()) + 1);
    }

    sort($gabarits);

    return $gabarits;
}

it('retire de leurs gabarits les balises link et img qui chargent une ressource d’une autre origine', function (string $cheminRelatif): void {
    $gabarit = <<<'BLADE'
        <link rel="preconnect" href="https://fonts.example.org">
        <link href="https://fonts.example.org/css?family=figtree:300,400" rel="stylesheet" />
        <LINK REL="dns-prefetch" HREF="//cdn.example.org">
        <img wire:ignore src="https://icones.example.org/{{ $host }}?fallback=false" loading="lazy" onerror="this.style.display='none'" />
        <img srcset='http://images.example.org/a.png 2x' alt="">
        <img src="{{ $user->avatar }}" alt="{{ $user->name }}">
        <link href="data:image/x-icon;base64,AAAA" rel="icon">
        <link rel="stylesheet" href="{{ asset('vendor/app.css') }}">
        <a href="https://docs.example.org">Documentation</a>
        BLADE;

    expect(ressourcesDistantesPrecompile($cheminRelatif, $gabarit))->toBe(<<<'BLADE'





        <img src="{{ $user->avatar }}" alt="{{ $user->name }}">
        <link href="data:image/x-icon;base64,AAAA" rel="icon">
        <link rel="stylesheet" href="{{ asset('vendor/app.css') }}">
        <a href="https://docs.example.org">Documentation</a>
        BLADE);
})->with([
    'vendor/laravel/horizon/resources/views/layout.blade.php',
    'vendor/laravel/pulse/resources/views/components/pulse.blade.php',
    'vendor/laravel/pulse/resources/views/livewire/slow-outgoing-requests.blade.php',
    'vendor/laravel/telescope/resources/views/layout.blade.php',
]);

it('ne touche à aucun autre gabarit', function (string $cheminRelatif): void {
    $gabarit = '<link href="https://fonts.example.org/css" rel="stylesheet" />';

    expect(ressourcesDistantesPrecompile($cheminRelatif, $gabarit))->toBe($gabarit);
})->with([
    'resources/views/app.blade.php',
    'resources/views/vendor/pulse/dashboard.blade.php',
    'vendor/filament/filament/resources/views/components/layout/base.blade.php',
    'vendor/laravel/horizon-voisin/resources/views/layout.blade.php',
]);

/**
 * La garde contre la dérive : chaque gabarit des trois paquets, tel que le
 * livre la version installée, compilé par le compilateur de l'application. Une
 * mise à jour qui y écrirait une ressource d'une autre origine d'une façon que
 * le précompilateur ne retire pas (une autre balise, un attribut `style`, une
 * feuille en ligne) fait tomber ce test. Une adresse que le PHP ou le
 * JavaScript d'un paquet construit à l'exécution ne s'écrit dans aucun
 * gabarit : il ne la voit pas, et seule la CSP la bloque.
 */
it('compile chaque gabarit de Horizon, Pulse et Telescope sans ressource d’une autre origine', function (): void {
    $gabarits = ressourcesDistantesGabaritsDesTableauxDeBord();
    $restantes = [];

    foreach ($gabarits as $gabarit) {
        Blade::setPath(base_path($gabarit));

        foreach (ressourcesDistantesDans(Blade::compileString((string) file_get_contents(base_path($gabarit)))) as $balise) {
            $restantes[] = $gabarit.' : '.$balise;
        }
    }

    expect($gabarits)->toContain('vendor/laravel/horizon/resources/views/layout.blade.php', 'vendor/laravel/pulse/resources/views/components/pulse.blade.php')
        ->and($restantes)->toBe([]);
});

it('sert Horizon sans ressource d’une autre origine', function (): void {
    config(['horizon.allowed_emails' => 'ops@example.org']);
    $autorise = User::factory()->create(['email' => 'ops@example.org']);

    $corps = (string) actingAs($autorise)->get('/horizon')->assertOk()->getContent();

    expect($corps)->toContain('<div id="horizon" v-cloak>')
        ->and(ressourcesDistantesDans($corps))->toBe([]);
});

it('sert Pulse sans ressource d’une autre origine', function (): void {
    $superAdministrateur = Admin::factory()->create();
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));

    $corps = (string) actingAs($superAdministrateur->fresh() ?? $superAdministrateur, 'admin')->get('/backoffice/pulse')->assertOk()->getContent();

    expect($corps)->toContain('Pulse')
        ->and(ressourcesDistantesDans($corps))->toBe([]);
});
