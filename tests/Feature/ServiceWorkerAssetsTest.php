<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteEnregistree;
use Illuminate\Support\Facades\Route;
use Spatie\Csp\Policy;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FermetureDuPrecache;
use Tests\Support\PagesGardees;

/**
 * Les entrées du precache que la construction a injectées dans public/sw.js,
 * de leur adresse à leur révision (nulle pour un actif dont le nom porte déjà
 * l'empreinte).
 *
 * @return array<string, string|null>
 */
function entreesDuPrecacheDuWorker(): array
{
    if (preg_match('/\[\{"revision".*?\}\]/s', (string) file_get_contents(public_path('sw.js')), $manifeste) !== 1) {
        return [];
    }

    /** @var list<array{url: string, revision: string|null}> $entrees */
    $entrees = json_decode($manifeste[0], true, 512, JSON_THROW_ON_ERROR);

    return array_column($entrees, 'revision', 'url');
}

/**
 * Le manifeste de la construction, `public/build/manifest.json`, ou un saut
 * du test quand rien n'est construit.
 *
 * @return array<string, mixed>
 */
function manifesteDeLaConstructionDuWorker(): array
{
    if (! is_file(public_path('build/manifest.json')) || ! is_file(public_path('sw.js'))) {
        test()->markTestSkipped('public/build absent : lancer npm run build.');
    }

    /** @var array<string, mixed> $manifeste */
    $manifeste = json_decode((string) file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);

    return $manifeste;
}

/**
 * Le fichier, sous son adresse, d'une entrée du manifeste.
 *
 * @param  array<string, mixed>  $manifeste
 */
function adresseDansLaConstructionDuWorker(array $manifeste, string $nom): string
{
    $entree = $manifeste[$nom] ?? null;

    expect($entree)->toBeArray("{$nom} est absent du manifeste de la construction.");

    /** @var array{file: string} $entree */
    return '/build/'.$entree['file'];
}

/**
 * Le worker et le manifeste sont des fichiers statiques à la racine de
 * public/ : servis sans Laravel, ils couvrent la portée / et n'ouvrent
 * aucune session. Le precache liste des entrées sous /build/ et des icônes
 * de public/ ; une seule en 404 et Workbox annule toute l'installation,
 * ce qui a laissé la production sans worker de la 1.5.0 à la 1.5.8 (#1683).
 */
it('construit le worker et le manifeste à la racine de public', function (): void {
    if (! is_file(public_path('build/manifest.json'))) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    expect(is_file(public_path('sw.js')))->toBeTrue()
        ->and(is_file(public_path('manifest.webmanifest')))->toBeTrue()
        ->and(is_file(public_path('build/sw.js')))->toBeFalse();
});

it('a un precache dont chaque entrée est un fichier servi en statique', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $urls = array_values(array_unique($trouvees[1]));

    expect($urls)->not->toBe([]);

    $muettes = [];

    foreach ($urls as $url) {
        $chemin = explode('?', $url)[0];
        // Un fichier de public/, que le serveur sert tel quel : jamais un script que PHP exécuterait (#2048).
        $statique = ! str_ends_with($chemin, '.php') && is_file(public_path(ltrim($chemin, '/')));

        if (! $statique) {
            $muettes[] = $url;
        }
    }

    expect($muettes)->toBe([]);
});

/*
 * L'URL d'enregistrement est décidée par vite.config, pas par le serveur :
 * le bundle doit demander /sw.js, seul emplacement qui couvre la portée /.
 */
it('enregistre le worker depuis la racine', function (): void {
    $bundles = (array) glob(public_path('build/assets/main-*.js'));

    if ($bundles === []) {
        $this->markTestSkipped('public/build absent : lancer npm run build.');
    }

    $source = implode('', array_map(static fn (mixed $path): string => (string) file_get_contents((string) $path), $bundles));

    expect($source)->toContain('/sw.js')
        ->and($source)->not->toContain('/build/sw.js');
});

/*
 * Sans réseau, le worker sert la page « hors ligne » à toute navigation
 * (#1966). Elle doit donc être dans le precache que la construction injecte,
 * avec la feuille de style qu'elle demande : une feuille absente du precache
 * ne viendrait pas sans réseau, et la page s'afficherait nue.
 */
it('précache la page « hors ligne » avec la feuille de style qu’elle demande', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $precache = array_map(static fn (string $url): string => explode('?', $url)[0], $trouvees[1]);

    expect($precache)->toContain('/build/hors-ligne.html')
        ->and(is_file(public_path('build/hors-ligne.html')))->toBeTrue();

    preg_match_all('/<link rel="stylesheet" href="([^"]+)">/', (string) file_get_contents(public_path('build/hors-ligne.html')), $feuilles);

    expect($feuilles[1])->not->toBe([])
        ->and(array_diff($feuilles[1], $precache))->toBe([]);
});

/*
 * La page est servie à quiconque ouvre l'application sans réseau, après la
 * déconnexion d'un compte comme avant la connexion d'un autre : un fichier
 * statique, construit une fois, qui ne porte ni page Inertia ni jeton. Le
 * precache ne contient d'ailleurs aucun document de l'application.
 */
it('ne précache aucune page de l’application, et rien d’un compte dans la page « hors ligne »', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);
    $documents = array_values(array_filter(
        $trouvees[1],
        static fn (string $url): bool => preg_match('#(\.html|/[^./]*)$#', explode('?', $url)[0]) === 1,
    ));

    expect($documents)->toBe(['/build/hors-ligne.html']);

    $page = (string) file_get_contents(public_path('build/hors-ligne.html'));

    expect($page)->not->toContain('data-page')
        ->and($page)->not->toContain('csrf')
        ->and($page)->toContain('Pas de réseau');
});

/*
 * La page « hors ligne » est la coquille de l'application (#2048, partie F) :
 * marquée `data-coquille`, elle charge le module de l'entrée construite, au
 * precache, qui démarre l'application sur une copie ou laisse son contenu.
 * Le worker la sert sous `script-src 'self'` : aucun script en ligne n'y
 * tournerait, ni gestionnaire d'évènement en attribut.
 */
it('fait de la page « hors ligne » la coquille : la marque, le module de l’entrée, aucun script en ligne', function (): void {
    $manifeste = manifesteDeLaConstructionDuWorker();
    $page = (string) file_get_contents(public_path('build/hors-ligne.html'));
    $entree = adresseDansLaConstructionDuWorker($manifeste, FermetureDuPrecache::ENTREE);

    preg_match_all('/<script\b([^>]*)>/i', $page, $scripts);

    expect($scripts[1])->toBe([' type="module" src="'.$entree.'"'])
        ->and($page)->toContain('<script type="module" src="'.$entree.'"></script>')
        ->and(preg_match('/<html\b[^>]*\sdata-coquille[\s>]/', $page))->toBe(1)
        ->and(preg_match('/\son[a-z]+\s*=/i', $page))->toBe(0)
        ->and(entreesDuPrecacheDuWorker())->toHaveKey($entree);

    // La politique que le worker pose sur elle n'admet que les scripts servis par l'application.
    expect(preg_match("/(?:^|;)script-src 'self'(?:;|$)/", enTetesDeLaCoquilleDuWorker()['Content-Security-Policy']))->toBe(1);
});

/*
 * Material Symbols, dont l'icône `cloud_off` qui marque une écriture en
 * attente, n'entrait au cache qu'à sa première utilisation (#2048) : la police
 * que la feuille de l'application demande est au precache.
 */
it('précache la police des icônes que la feuille de l’application demande', function (): void {
    $feuilles = (array) glob(public_path('build/assets/main-*.css'));

    if (! is_file(public_path('sw.js')) || $feuilles === []) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    $demandees = [];

    foreach ($feuilles as $feuille) {
        preg_match_all('#/build/assets/material-symbols-outlined-[\w-]+\.woff2#', (string) file_get_contents((string) $feuille), $trouvees);
        $demandees = [...$demandees, ...$trouvees[0]];
    }

    expect($demandees)->not->toBe([])
        ->and(array_diff(array_unique($demandees), array_keys(entreesDuPrecacheDuWorker())))->toBe([]);
});

/*
 * Le favicon, les icônes de l'application installée, celles des notifications
 * et les SVG du logo vivent à la racine de public/, que les globs du precache
 * ne lisent pas : aucun ne venait sans réseau (#2048). Chacun entre au
 * precache avec l'empreinte de son contenu pour révision, pour qu'une icône
 * redessinée soit téléchargée de nouveau.
 */
it('précache chaque icône de public/ avec l’empreinte de son contenu', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    $icones = array_values(array_filter(
        (array) scandir(public_path()),
        static fn (mixed $nom): bool => is_string($nom) && is_file(public_path($nom)) && preg_match('/\.(ico|png|svg|webp)$/', $nom) === 1,
    ));
    $precache = entreesDuPrecacheDuWorker();

    expect($icones)->toContain('logo.svg')
        ->toContain('icone.svg')
        ->toContain('favicon.ico')
        ->toContain('pwa-192x192.png')
        ->toContain('badge-96x96.png');

    foreach ($icones as $icone) {
        expect($precache)->toHaveKey('/'.$icone)
            ->and($precache['/'.$icone])->toBe(hash_file('sha256', public_path($icone)));
    }
});

/*
 * Une copie servie sans réseau monte le morceau de sa page, puis tout ce qu'il
 * importe (#2048, partie E). Des motifs par nom n'en prenaient qu'une partie :
 * la séance demande 23 fichiers JS et 3 feuilles, et sa feuille n'y était pas.
 * Le precache prend désormais la fermeture de l'entrée et de chaque page
 * gardée, que `greffonDeLaFermetureDuPrecache` lit dans le manifeste ; elle se
 * refait ici sans lui, et le code précaché doit être exactement elle : ni un
 * morceau de moins, ni la fermeture de toutes les pages, que l'entrée charge à
 * la demande.
 */
it('précache la fermeture de l’entrée et des pages gardées, et rien d’autre du code', function (): void {
    $manifeste = manifesteDeLaConstructionDuWorker();
    $attendus = array_map(
        static fn (string $fichier): string => '/build/'.$fichier,
        FermetureDuPrecache::fichiers($manifeste),
    );
    $code = array_values(array_filter(
        array_keys(entreesDuPrecacheDuWorker()),
        static fn (string $url): bool => preg_match('#^/build/.+\.(js|css)$#', $url) === 1,
    ));
    sort($code);

    expect($attendus)->toContain(adresseDansLaConstructionDuWorker($manifeste, FermetureDuPrecache::ENTREE))
        ->and($code)->toBe($attendus);
});

it('précache le morceau et les feuilles de chaque page gardée', function (string $composant): void {
    $manifeste = manifesteDeLaConstructionDuWorker();
    $nom = FermetureDuPrecache::DOSSIER_DES_PAGES.$composant.'.vue';
    $precache = entreesDuPrecacheDuWorker();

    expect($precache)->toHaveKey(adresseDansLaConstructionDuWorker($manifeste, $nom));

    /** @var array{css?: list<string>} $entree */
    $entree = $manifeste[$nom];

    foreach ($entree['css'] ?? [] as $feuille) {
        expect($precache)->toHaveKey('/build/'.$feuille);
    }
})->with(fn (): array => array_column(PagesGardees::liste(), 'composant', 'composant'));

/**
 * Les fichiers que l'entrée charge d'emblée, sous leur adresse : le sien et
 * ceux qu'il importe statiquement, de proche en proche, sans rien de ce
 * qu'il charge à la demande.
 *
 * @param  array<string, mixed>  $manifeste
 * @return list<string>
 */
function fichiersStatiquesDeLEntreeDuWorker(array $manifeste): array
{
    $fichiers = [];
    $vus = [];
    $aSuivre = [FermetureDuPrecache::ENTREE];

    while ($aSuivre !== []) {
        $nom = array_pop($aSuivre);

        if (isset($vus[$nom])) {
            continue;
        }

        $vus[$nom] = true;
        $fichiers[] = adresseDansLaConstructionDuWorker($manifeste, $nom);

        /** @var array{imports?: list<string>} $entree */
        $entree = $manifeste[$nom];

        foreach ($entree['imports'] ?? [] as $importe) {
            $aSuivre[] = $importe;
        }
    }

    return $fichiers;
}

/*
 * La coquille monte la page `HorsLigne` à une adresse sans copie, et son
 * démarrage (`Utils/demarrageHorsLigne.js`) vient de l'entrée même (#2048,
 * partie F) : chargé à la demande, il ne le serait qu'en mode coquille, donc
 * sans réseau, et ne serait jamais au precache. Le module n'a pas de morceau à
 * lui dans le manifeste : il se reconnaît dans le code que l'entrée charge
 * d'emblée, aux noms qu'il écrit (`pagesSansReseau`, la prop de la page
 * `HorsLigne`) et qu'il appelle au magasin (`lireAuDemarrage`), que la
 * minification garde.
 */
it('précache la page HorsLigne, et le démarrage de la coquille dans ce que l’entrée charge d’emblée', function (): void {
    $manifeste = manifesteDeLaConstructionDuWorker();
    $precache = entreesDuPrecacheDuWorker();
    $nom = FermetureDuPrecache::DOSSIER_DES_PAGES.'HorsLigne.vue';

    expect(FermetureDuPrecache::pagesPrecacheesSansCopie())->toBe(['HorsLigne'])
        ->and($precache)->toHaveKey(adresseDansLaConstructionDuWorker($manifeste, $nom));

    /** @var array{css?: list<string>} $entree */
    $entree = $manifeste[$nom];

    foreach ($entree['css'] ?? [] as $feuille) {
        expect($precache)->toHaveKey('/build/'.$feuille);
    }

    $statiques = fichiersStatiquesDeLEntreeDuWorker($manifeste);
    $code = '';

    foreach ($statiques as $fichier) {
        expect($precache)->toHaveKey($fichier);
        $code .= (string) file_get_contents(public_path(ltrim($fichier, '/')));
    }

    expect($code)->toContain('pagesSansReseau')
        ->and($code)->toContain('lireAuDemarrage');
});

/*
 * Les graphiques restent dehors (décision 9 du propriétaire) : sans réseau,
 * chacun dit « Graphique indisponible hors ligne ». La liste qui les nomme est
 * exacte (la construction s'arrête sur un graphique d'une page gardée qu'elle
 * ne nomme pas), et Chart.js, que seul `BaseChart` importe, reste dehors avec
 * eux.
 */
it('laisse hors du precache les graphiques nommés, et Chart.js avec eux', function (): void {
    $manifeste = manifesteDeLaConstructionDuWorker();
    $precache = entreesDuPrecacheDuWorker();
    $graphiques = FermetureDuPrecache::graphiquesIndisponiblesHorsLigne();

    expect($graphiques)->not->toBeEmpty();

    foreach ($graphiques as $graphique) {
        expect(base_path($graphique))->toBeReadableFile()
            ->and($precache)->not->toHaveKey(adresseDansLaConstructionDuWorker($manifeste, $graphique));
    }

    $chartJs = array_values(array_filter(array_keys($manifeste), static fn (string $nom): bool => str_starts_with($nom, '_BaseChart-')));

    expect($chartJs)->not->toBeEmpty();

    foreach ($chartJs as $nom) {
        expect($precache)->not->toHaveKey(adresseDansLaConstructionDuWorker($manifeste, $nom));
    }
});

/*
 * Workbox refuse, à l'installation du worker, deux entrées de même adresse et
 * de révisions différentes : un motif qui reprendrait un fichier de la
 * fermeture laisserait l'appareil sans worker neuf.
 */
it('ne précache aucune adresse deux fois', function (): void {
    if (! is_file(public_path('sw.js'))) {
        $this->markTestSkipped('public/sw.js absent : lancer npm run build.');
    }

    preg_match_all('/"url":"([^"]+)"/', (string) file_get_contents(public_path('sw.js')), $trouvees);

    expect($trouvees[1])->not->toBe([])
        ->and($trouvees[1])->toBe(array_values(array_unique($trouvees[1])));
});

/**
 * Les en-têtes que le worker pose sur la page « hors ligne » qu'il sert
 * (`resources/js/sw/enTetesDeLaCoquille.json`, #2048, partie F), du nom à la
 * valeur.
 *
 * @return array<string, string>
 */
function enTetesDeLaCoquilleDuWorker(): array
{
    /** @var array<string, string> $enTetes */
    $enTetes = json_decode((string) file_get_contents(resource_path('js/sw/enTetesDeLaCoquille.json')), true, 512, JSON_THROW_ON_ERROR);

    return $enTetes;
}

/**
 * Les débuts de chemin des navigations qui ne reçoivent jamais la page
 * « hors ligne » ni le délai (`PREFIXES_SANS_COQUILLE`), lus comme un texte,
 * une entrée par ligne : une ligne d'une autre forme fait échouer la lecture
 * au lieu d'être sautée.
 *
 * @return list<string>
 */
function prefixesSansCoquilleDuWorker(): array
{
    $source = (string) file_get_contents(resource_path('js/sw/navigationsHorsLigne.js'));
    $debut = strpos($source, 'export const PREFIXES_SANS_COQUILLE = Object.freeze([');

    expect($debut)->not->toBeFalse('PREFIXES_SANS_COQUILLE est introuvable dans navigationsHorsLigne.js.');

    $prefixes = [];

    foreach (array_slice(explode("\n", substr($source, (int) $debut)), 1) as $ligne) {
        if (trim($ligne) === '])') {
            return $prefixes;
        }

        if (preg_match("#^\\s*'(/[a-z_/-]+)',?\\s*$#", $ligne, $trouve) !== 1) {
            throw new RuntimeException("Ligne inattendue dans PREFIXES_SANS_COQUILLE : {$ligne}");
        }

        $prefixes[] = $trouve[1];
    }

    throw new RuntimeException('PREFIXES_SANS_COQUILLE ne se ferme pas.');
}

/*
 * Le precache garde la page « hors ligne » telle qu'un fichier statique de
 * public/build : sans la politique de sécurité du contenu ni les autres
 * en-têtes que Laravel pose sur une page. Le worker l'enveloppe donc des
 * siens (#2048, partie F), qui doivent être exactement ceux du serveur :
 * chaque en-tête de `SecurityHeaders`, et la politique de `CustomPolicy` en
 * production, sans nonce, plus `frame-ancestors 'self'`. Et le `Cache-Control`
 * d'une page de compte (`HandleInertiaRequests`) : la coquille y monte une
 * copie, et sans `no-store` le cache de retour arrière la rendrait au bouton
 * Retour après la fin de la session, témoin expiré, ce qu'il refuse à une page
 * `no-store` dont un cookie a changé. Un en-tête ajouté au middleware, ou une
 * source ajoutée à la politique, fait échouer ce test tant que la coquille ne
 * le porte pas ; le worker construit les porte tels quels.
 */
it('enveloppe la page « hors ligne » des en-têtes de SecurityHeaders et de la politique de production, sans nonce', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config(['app.env' => 'production', 'csp.nonce_enabled' => false]);
    app()->instance('request', Request::create('/dashboard'));

    /** @var list<class-string<Spatie\Csp\Preset>> $preReglages */
    $preReglages = config()->array('csp.presets');
    $politique = Policy::create(presets: $preReglages, directives: config()->array('csp.directives'))->getContents();
    $reponse = new SecurityHeaders()->handle(Request::create('/dashboard'), static fn (): Response => new Response());
    $attendus = ['content-security-policy' => $politique.";frame-ancestors 'self'"];

    foreach ($reponse->headers->all() as $nom => $valeurs) {
        if ($nom !== 'date') {
            $attendus[$nom] = (string) $valeurs[0];
        }
    }

    /*
     * La réponse nue de Symfony porte `no-cache, private` par défaut : celui
     * d'une page de compte le remplace, comme `HandleInertiaRequests` le fait.
     */
    $attendus['cache-control'] = HandleInertiaRequests::CACHE_D_UNE_PAGE_DE_COMPTE;

    $coquille = array_change_key_case(enTetesDeLaCoquilleDuWorker());
    ksort($attendus);
    ksort($coquille);

    expect($coquille)->toBe($attendus)
        ->and($coquille['content-security-policy'])->not->toContain('nonce-')
        ->and($coquille['content-security-policy'])->not->toContain('unsafe-eval')
        ->and($coquille)->toHaveKeys(['x-frame-options', 'x-content-type-options', 'referrer-policy', 'cross-origin-opener-policy', 'permissions-policy'])
        ->and($coquille['cache-control'])->toBe('no-store, private');

    if (is_file(public_path('sw.js'))) {
        $worker = (string) file_get_contents(public_path('sw.js'));

        foreach (enTetesDeLaCoquilleDuWorker() as $nom => $valeur) {
            expect(str_contains($worker, $valeur))->toBeTrue("public/sw.js ne porte pas l'en-tête {$nom} de la coquille : relancer npm run build.");
        }
    }
});

/*
 * Ni délai ni page « hors ligne » pour le rappel d'un fournisseur de
 * connexion, le panneau et ses tableaux de bord, Horizon et Telescope, les
 * liens signés des courriels (vérification, réinitialisation, ouverture du
 * compte, confirmation d'une nouvelle adresse) et les routes de Dusk (#2048,
 * partie F) : coupé à huit secondes, le rappel poserait la session sur une
 * réponse que personne ne lit, et la page « hors ligne », rechargée au retour
 * du réseau, redemanderait le lien signé en arrière-plan.
 * Le worker ne lit pas la configuration : chaque début de chemin qu'il écrit
 * en dur se rapproche ici de la route ou du chemin configuré qu'il couvre,
 * et aucun ne touche une page de la salle.
 */
it('ne donne jamais la page « hors ligne » ni le délai au rappel de connexion, au panneau et à Horizon', function (): void {
    $prefixes = prefixesSansCoquilleDuWorker();
    $couvert = static fn (string $chemin): bool => array_filter(
        $prefixes,
        static fn (string $prefixe): bool => str_starts_with($chemin, $prefixe),
    ) !== [];
    $chemins = [
        'rappel de la connexion sociale' => route('social.callback', ['provider' => 'google'], false),
        'redirection vers un fournisseur' => route('social.redirect', ['provider' => 'google'], false),
        'panneau' => '/'.Filament::getPanel('admin')->getPath(),
        'Pulse' => '/'.config()->string('pulse.path'),
        'journaux' => '/'.config()->string('log-viewer.route_path'),
        'Horizon' => '/'.config()->string('horizon.path'),
        'Telescope' => '/'.config()->string('telescope.path'),
        'lien de vérification' => route('verification.verify', ['id' => 7, 'hash' => 'empreinte'], false),
        'lien de réinitialisation' => route('password.reset', ['token' => 'jeton'], false),
        'lien d’ouverture du compte' => route('inscription.ouvrir', ['id' => 7, 'hash' => 'empreinte'], false),
        'lien de confirmation d’une nouvelle adresse' => route('adresse.confirmer', [], false),
        'connexion de Dusk' => '/_dusk/login/7',
    ];

    foreach ($chemins as $quoi => $chemin) {
        expect($couvert($chemin))->toBeTrue("{$quoi} ({$chemin}) recevrait la page « hors ligne ».");
    }

    foreach (PagesGardees::liste() as $page) {
        expect($couvert($page['chemin']))->toBeFalse("{$page['chemin']} perdrait son délai.");
    }

    foreach ($prefixes as $prefixe) {
        expect(array_filter($chemins, static fn (string $chemin): bool => str_starts_with($chemin, $prefixe)))
            ->not->toBe([], "{$prefixe} ne couvre plus aucune route.");
    }

    expect(collect(Route::getRoutes()->getRoutes())
        ->map(static fn (RouteEnregistree $route): string => '/'.ltrim($route->uri(), '/'))
        ->filter(static fn (string $uri): bool => str_starts_with($uri, '/_dusk/'))
        ->values()
        ->all())->not->toBe([]);
});
