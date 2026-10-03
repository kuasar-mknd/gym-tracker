<?php

declare(strict_types=1);

use App\Support\Charte;

/*
 * Les icônes de l'application (#1850).
 *
 * L'icône était un SVG seul. iOS n'en veut pas pour `apple-touch-icon` : sans
 * PNG, l'écran d'accueil d'un iPhone montrait une capture de la page à la place
 * du logo. Le manifeste annonçait ce même SVG en « 192x192 512x512 » et en
 * « any maskable » d'un bloc, `favicon.ico` faisait zéro octet, et le worker
 * posait sur chaque notification un badge, `/badge.svg`, qui n'a jamais existé.
 * Aucun de ces défauts ne faisait échouer une page ni un test : d'où ces
 * gardes.
 *
 * Les PNG sont versionnés et se régénèrent par la recette de
 * `pwa-assets.config.js`. Ils se lisent ici sans gd, que l'image Docker
 * n'installe pas et que la CI ne demande pas : un PNG s'ouvre sur une
 * signature de huit octets suivie de l'en-tête IHDR, qui porte les dimensions
 * et le type de couleur, et ses pixels ne demandent que zlib.
 */

/**
 * L'en-tête IHDR d'un PNG, lu dans ses octets : ceux d'un fichier de public/,
 * ou l'image que range un favicon. `$nom` le désigne dans les messages.
 *
 * @return array{largeur: int, hauteur: int, profondeur: int, typeDeCouleur: int, entrelacement: int}
 */
function iconePngEnTete(string $png, string $nom): array
{
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n", "{$nom} n'est pas un PNG.")
        ->and(substr($png, 12, 4))->toBe('IHDR', "{$nom} n'ouvre pas sur son en-tête IHDR.");

    $champs = unpack('Nlargeur/Nhauteur/Cprofondeur/CtypeDeCouleur/Ccompression/Cfiltre/Centrelacement', $png, 16);

    expect($champs)->toBeArray();

    /** @var array{largeur: int, hauteur: int, profondeur: int, typeDeCouleur: int, entrelacement: int} $champs */
    return $champs;
}

/**
 * Les pixels d'un PNG, quatre octets par pixel (rouge, vert, bleu, alpha),
 * ligne après ligne.
 *
 * L'en-tête ne suffit pas à dire qu'une icône est opaque : le générateur écrit
 * du RVBA même quand aucun pixel n'est transparent. On décompresse donc les
 * blocs IDAT et on défait le filtre de chaque ligne (spécification PNG, § 9),
 * pour lire l'alpha réel. Seul le 8 bits non entrelacé est lu, en RVB, RVBA ou
 * palette : c'est ce que sharp écrit.
 */
function iconePngPixels(string $png, string $nom): string
{
    $entete = iconePngEnTete($png, $nom);
    $canaux = [2 => 3, 3 => 1, 6 => 4][$entete['typeDeCouleur']] ?? null;

    expect($canaux)->not->toBeNull("{$nom} : type de couleur {$entete['typeDeCouleur']} non lu par ce test.")
        ->and($entete['profondeur'])->toBe(8, "{$nom} : seule la profondeur de 8 bits est lue.")
        ->and($entete['entrelacement'])->toBe(0, "{$nom} : un PNG entrelacé n'est pas lu.");

    /** @var int<1, 4> $canaux */
    $blocs = ['IDAT' => '', 'PLTE' => '', 'tRNS' => ''];
    $position = 8;

    while ($position + 8 <= strlen($png)) {
        $longueur = unpack('N', $png, $position);
        $longueur = is_array($longueur) && is_int($longueur[1] ?? null) ? $longueur[1] : 0;
        $type = substr($png, $position + 4, 4);

        if (array_key_exists($type, $blocs)) {
            $blocs[$type] .= substr($png, $position + 8, $longueur);
        }

        $position += 12 + $longueur;
    }

    $brut = gzuncompress($blocs['IDAT']);

    expect($brut)->toBeString("{$nom} : les blocs IDAT ne se décompressent pas.");

    /** @var string $brut */
    $pas = $entete['largeur'] * $canaux;
    $precedente = array_fill(0, $pas, 0);
    $pixels = '';

    for ($ligne = 0; $ligne < $entete['hauteur']; $ligne++) {
        $debut = $ligne * ($pas + 1);
        $filtre = ord($brut[$debut]);

        /** @var list<int> $courante */
        $courante = array_values((array) unpack('C*', substr($brut, $debut + 1, $pas)));

        for ($octet = 0; $octet < $pas; $octet++) {
            $gauche = $octet >= $canaux ? $courante[$octet - $canaux] : 0;
            $haut = $precedente[$octet];
            $hautGauche = $octet >= $canaux ? $precedente[$octet - $canaux] : 0;
            $estimation = $gauche + $haut - $hautGauche;
            $paeth = match (true) {
                abs($estimation - $gauche) <= abs($estimation - $haut) && abs($estimation - $gauche) <= abs($estimation - $hautGauche) => $gauche,
                abs($estimation - $haut) <= abs($estimation - $hautGauche) => $haut,
                default => $hautGauche,
            };

            $courante[$octet] = ($courante[$octet] + match ($filtre) {
                1 => $gauche,
                2 => $haut,
                3 => intdiv($gauche + $haut, 2),
                4 => $paeth,
                default => 0,
            }) & 0xFF;
        }

        foreach (array_chunk($courante, $canaux) as $pixel) {
            $pixels .= match ($canaux) {
                4 => pack('C4', ...$pixel),
                3 => pack('C4', $pixel[0], $pixel[1], $pixel[2], 255),
                default => substr($blocs['PLTE'], $pixel[0] * 3, 3)
                    .($pixel[0] < strlen($blocs['tRNS']) ? $blocs['tRNS'][$pixel[0]] : "\xFF"),
            };
        }

        $precedente = $courante;
    }

    return $pixels;
}

/**
 * La couleur d'un pixel, en `#rrvvbb` minuscule comme dans la charte.
 */
function iconePngCouleurDuPixel(string $pixels, int $rang): string
{
    return '#'.bin2hex(substr($pixels, $rang * 4, 3));
}

/**
 * Vérifie qu'une icône montre le logo, dans les couleurs de la charte.
 *
 * La taille et l'opacité ne disent pas ce que l'icône dessine : un carré uni
 * aux couleurs du thème retiré les tient aussi. Deux pixels du dessin le
 * disent : le centre, où se croisent les tracés, et le milieu de la largeur à
 * un huitième de la hauteur, entre le bord et le cercle, où ne paraît que le
 * fond. Un huitième plutôt que le bord même : les icônes « any » et le favicon
 * gardent une marge transparente autour du logo.
 */
function iconePngPorteLeLogo(string $png, string $nom): void
{
    $entete = iconePngEnTete($png, $nom);
    $pixels = iconePngPixels($png, $nom);
    $milieu = intdiv($entete['largeur'], 2);
    $rangs = [
        'fond' => intdiv($entete['hauteur'], 8) * $entete['largeur'] + $milieu,
        'traces' => intdiv($entete['hauteur'], 2) * $entete['largeur'] + $milieu,
    ];

    expect(array_map(static fn (int $rang): string => iconePngCouleurDuPixel($pixels, $rang), $rangs))
        ->toBe(['fond' => Charte::jeton('accent-primary-fill'), 'traces' => Charte::jeton('text-on-dark-accent')], "{$nom} ne montre pas le logo aux couleurs de la charte : à régénérer, voir pwa-assets.config.js.")
        ->and(array_map(static fn (int $rang): string => $pixels[$rang * 4 + 3], $rangs))
        ->toBe(['fond' => "\xFF", 'traces' => "\xFF"], "{$nom} : le logo y est transparent.");
}

/**
 * Les valeurs d'alpha distinctes d'une image : `["\xFF"]` pour une image opaque.
 *
 * @return list<string>
 */
function iconePngAlphasDistincts(string $pixels): array
{
    $alphas = [];

    for ($rang = 3; $rang < strlen($pixels); $rang += 4) {
        $alphas[$pixels[$rang]] = true;
    }

    return array_map(strval(...), array_keys($alphas));
}

/**
 * Le manifeste de l'application, décodé.
 *
 * @return array<string, mixed>
 */
function iconeManifeste(): array
{
    $manifeste = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifeste)->toBeArray();

    /** @var array<string, mixed> $manifeste */
    return $manifeste;
}

/**
 * Les icônes déclarées par le manifeste.
 *
 * @return list<array<string, string>>
 */
function iconesDuManifeste(): array
{
    $icones = iconeManifeste()['icons'] ?? null;

    expect($icones)->toBeArray();
    expect($icones)->not->toBeEmpty();

    /** @var list<array<string, string>> $icones */
    return $icones;
}

/**
 * Le fichier de public/ que sert une adresse absolue comme `/logo.svg`.
 */
function iconeFichierServi(string $adresse): string
{
    expect($adresse)->toStartWith('/', "« {$adresse} » n'est pas une adresse absolue : elle se résoudrait contre la page courante.");

    return public_path(ltrim($adresse, '/'));
}

/**
 * Les adresses que la mise en page donne aux navigateurs, par valeur de `rel`.
 *
 * @return array<string, list<string>>
 */
function iconesDeLaMiseEnPage(): array
{
    $gabarit = (string) file_get_contents(resource_path('views/app.blade.php'));
    preg_match_all('/<link\s+rel="(icon|apple-touch-icon)"\s+href="([^"]+)"/', $gabarit, $liens, PREG_SET_ORDER);

    $parRel = [];

    foreach ($liens as $lien) {
        $parRel[$lien[1]][] = $lien[2];
    }

    return $parRel;
}

it('déclare dans le manifeste des icônes qui existent à la taille annoncée', function (): void {
    foreach (iconesDuManifeste() as $icone) {
        $chemin = iconeFichierServi($icone['src']);

        expect(is_file($chemin))->toBeTrue("{$icone['src']} est déclarée par le manifeste et n'existe pas dans public/.")
            ->and($icone['type'] ?? null)->toBe('image/png', "{$icone['src']} : le manifeste ne déclare que des PNG, dont ce test sait lire la taille.");

        $entete = iconePngEnTete((string) file_get_contents($chemin), $icone['src']);

        expect("{$entete['largeur']}x{$entete['hauteur']}")->toBe($icone['sizes'] ?? null, "{$icone['src']} n'a pas la taille que le manifeste annonce.");
    }
});

it('sépare l’icône ordinaire de l’icône masquable', function (): void {
    $icones = iconesDuManifeste();
    $ordinaires = array_column(array_filter($icones, static fn (array $icone): bool => ($icone['purpose'] ?? 'any') === 'any'), 'sizes');
    $masquables = array_filter($icones, static fn (array $icone): bool => ($icone['purpose'] ?? 'any') === 'maskable');
    $melangees = array_filter($icones, static fn (array $icone): bool => preg_match('/\bany\b.*\bmaskable\b|\bmaskable\b.*\bany\b/', $icone['purpose'] ?? '') === 1);

    /*
     * « any maskable » d'un bloc donne la même image aux deux usages : soit le
     * dessin est rogné par le masque du lanceur, soit l'icône ordinaire flotte
     * dans une marge pensée pour lui.
     */
    expect($melangees)->toBe([], 'Une icône ne sert pas à la fois en « any » et en « maskable ».')
        ->and($ordinaires)->toContain('192x192', '512x512')
        ->and($masquables)->not->toBeEmpty();
});

it('ouvre l’application installée sur la couleur de la page', function (): void {
    $manifeste = iconeManifeste();

    expect($manifeste['background_color'] ?? null)->toBe(Charte::jeton('surface-page'))
        ->and($manifeste['theme_color'] ?? null)->toBe(Charte::jeton('surface-page'));
});

it('donne à iOS une icône PNG de 180 px sans transparence', function (): void {
    $appleTouchIcon = iconesDeLaMiseEnPage()['apple-touch-icon'] ?? [];

    expect($appleTouchIcon)->toHaveCount(1, 'La mise en page doit déclarer un seul apple-touch-icon.');

    $chemin = iconeFichierServi($appleTouchIcon[0]);

    expect(is_file($chemin))->toBeTrue("{$appleTouchIcon[0]} n'existe pas dans public/.");

    $png = (string) file_get_contents($chemin);
    $entete = iconePngEnTete($png, $appleTouchIcon[0]);

    expect([$entete['largeur'], $entete['hauteur']])->toBe([180, 180]);

    /*
     * iOS remplit de noir chaque pixel transparent : des coins arrondis
     * transparents sortent en coins noirs sur l'écran d'accueil.
     */
    expect(iconePngAlphasDistincts(iconePngPixels($png, $appleTouchIcon[0])))->toBe(["\xFF"], "{$appleTouchIcon[0]} porte des pixels transparents, qu'iOS peindrait en noir.");
});

it('sert le favicon du logo, à la taille que la mise en page annonce', function (): void {
    $favicon = (string) file_get_contents(public_path('favicon.ico'));

    expect(strlen($favicon))->toBeGreaterThan(0, 'public/favicon.ico est vide.')
        ->and(substr($favicon, 0, 4))->toBe("\x00\x00\x01\x00", 'public/favicon.ico ne porte pas l’en-tête d’un fichier ICO.');

    $icones = iconesDeLaMiseEnPage()['icon'] ?? [];

    expect($icones)->toContain('/favicon.ico');

    foreach ($icones as $adresse) {
        expect(is_file(iconeFichierServi($adresse)))->toBeTrue("{$adresse} est déclarée par la mise en page et n'existe pas dans public/.");
    }

    /*
     * L'en-tête seul ne dit rien de l'image : un carré noir de 16 px le porte
     * aussi. Le répertoire qui le suit donne, à l'octet 4, le nombre d'images,
     * puis pour la première sa largeur et sa hauteur (0 vaut 256), la taille et
     * la position de ses données, où le générateur range un PNG.
     */
    preg_match('/<link\s+rel="icon"\s+href="\/favicon\.ico"\s+sizes="([^"]+)"/', (string) file_get_contents(resource_path('views/app.blade.php')), $lienDuFavicon);
    $tailleAnnoncee = $lienDuFavicon[1] ?? null;
    $repertoire = unpack('vnombre/Clargeur/Chauteur/x6/Vtaille/Vposition', $favicon, 4);

    expect($tailleAnnoncee)->toBe('48x48', 'La mise en page doit annoncer la taille du favicon.')
        ->and($repertoire)->toBeArray();

    /** @var array{nombre: int, largeur: int, hauteur: int, taille: int, position: int} $repertoire */
    $cote = static fn (int $octet): int => $octet === 0 ? 256 : $octet;
    $image = substr($favicon, $repertoire['position'], $repertoire['taille']);

    expect($repertoire['nombre'])->toBeGreaterThanOrEqual(1, 'public/favicon.ico ne range aucune image.')
        ->and("{$cote($repertoire['largeur'])}x{$cote($repertoire['hauteur'])}")->toBe($tailleAnnoncee, 'public/favicon.ico n’a pas la taille que la mise en page annonce.');

    $entete = iconePngEnTete($image, 'L’image de public/favicon.ico');

    expect("{$entete['largeur']}x{$entete['hauteur']}")->toBe($tailleAnnoncee, 'L’image de public/favicon.ico n’a pas la taille que son répertoire annonce.');

    iconePngPorteLeLogo($image, 'L’image de public/favicon.ico');
});

it('peint le logo du couple fond et texte de l’utilitaire accent-fill', function (): void {
    /*
     * Un SVG statique ne lit pas les variables CSS : ses couleurs sont écrites
     * en clair, et c'est ici qu'elles se comparent à la charte. Le couple vient
     * de `accent-fill`, qui pose le fond ET son texte : la charte interdit de
     * choisir soi-même un texte sur un fond.
     */
    $fond = Charte::jeton('accent-primary-fill');
    $trace = Charte::jeton('text-on-dark-accent');
    $logo = (string) file_get_contents(public_path('logo.svg'));
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $logo, $couleurs);

    expect(array_values(array_unique(array_map(strtolower(...), $couleurs[0]))))->toEqualCanonicalizing([$fond, $trace])
        ->and($logo)->toContain("fill=\"{$fond}\"")
        ->and($logo)->toContain("stroke=\"{$trace}\"");
});

it('tire les icônes opaques du fond du logo, sans marge', function (): void {
    $adresses = [
        ...(iconesDeLaMiseEnPage()['apple-touch-icon'] ?? []),
        ...array_column(array_filter(iconesDuManifeste(), static fn (array $icone): bool => ($icone['purpose'] ?? 'any') === 'maskable'), 'src'),
    ];

    expect($adresses)->toHaveCount(2);

    foreach ($adresses as $adresse) {
        $png = (string) file_get_contents(iconeFichierServi($adresse));
        $pixels = iconePngPixels($png, $adresse);
        $entete = iconePngEnTete($png, $adresse);
        $coins = [0, $entete['largeur'] - 1, ($entete['hauteur'] - 1) * $entete['largeur'], $entete['largeur'] * $entete['hauteur'] - 1];

        /*
         * Quatre coins au fond de la charte : ni la marge blanche que le
         * générateur pose par défaut, ni la transparence des coins arrondis du
         * logo. Une couleur retouchée dans la charte et dans logo.svg sans
         * relancer la recette se voit aussi ici.
         */
        expect(array_map(static fn (int $rang): string => iconePngCouleurDuPixel($pixels, $rang), $coins))
            ->toBe(array_fill(0, 4, Charte::jeton('accent-primary-fill')), "{$adresse} : à régénérer, voir pwa-assets.config.js.")
            ->and(iconePngAlphasDistincts($pixels))->toBe(["\xFF"], "{$adresse} porte des pixels transparents.");
    }
});

it('dessine le logo aux couleurs de la charte sur chaque icône PNG', function (): void {
    /*
     * Les icônes « any » comprises : pwa-192x192.png est aussi l'icône des
     * trois notifications, et le lanceur montre l'une ou l'autre selon
     * l'appareil.
     */
    $adresses = [
        ...array_column(iconesDuManifeste(), 'src'),
        ...(iconesDeLaMiseEnPage()['apple-touch-icon'] ?? []),
    ];

    expect($adresses)->toContain('/pwa-192x192.png', '/apple-touch-icon-180x180.png');

    foreach ($adresses as $adresse) {
        iconePngPorteLeLogo((string) file_get_contents(iconeFichierServi($adresse)), $adresse);
    }
});

it('cite dans le worker et les notifications des icônes qui existent', function (): void {
    $notifications = glob(app_path('Notifications/*.php'));
    $sources = [resource_path('js/sw.js'), ...(is_array($notifications) ? $notifications : [])];
    $adresses = [];

    foreach ($sources as $source) {
        $contenu = (string) file_get_contents($source);
        preg_match_all('/(?:icon|badge)(?:\(|:\s*(?:[^\'\n]*\|\|\s*)?)\'(\/[^\']+)\'/', $contenu, $trouvees);

        foreach ($trouvees[1] as $adresse) {
            $adresses[$adresse][] = basename($source);
        }
    }

    /*
     * Le worker pose un badge sur chaque notification, et les trois
     * notifications leur icône : une liste vide voudrait dire que l'extraction
     * ne voit plus rien, pas que tout va bien.
     */
    expect($adresses)->not->toBeEmpty();

    $absentes = array_filter(
        $adresses,
        static fn (string $adresse): bool => ! is_file(iconeFichierServi($adresse)),
        ARRAY_FILTER_USE_KEY
    );

    expect($absentes)->toBe([], 'Ces icônes sont citées et n’existent pas dans public/ : la notification part sans elles.');
});

it('dessine le badge des notifications en blanc sur transparent', function (): void {
    /*
     * Android ne lit que l'alpha du badge, la petite icône de la barre d'état :
     * un badge opaque y devient un carré plein. Le blanc est pour les systèmes
     * qui l'afficheraient tel quel.
     */
    preg_match("/badge:\s*'([^']+)'/", (string) file_get_contents(resource_path('js/sw.js')), $badge);

    expect($badge[1] ?? null)->toBe('/badge-96x96.png');

    $png = (string) file_get_contents(iconeFichierServi('/badge-96x96.png'));
    $entete = iconePngEnTete($png, '/badge-96x96.png');
    $pixels = str_split(iconePngPixels($png, '/badge-96x96.png'), 4);
    $visibles = array_filter($pixels, static fn (string $pixel): bool => $pixel[3] !== "\x00");
    $teintes = array_unique(array_map(static fn (string $pixel): string => substr($pixel, 0, 3), $visibles));

    expect([$entete['largeur'], $entete['hauteur']])->toBe([96, 96])
        ->and($pixels[0][3])->toBe("\x00", 'Le coin du badge doit être transparent.')
        ->and(count($visibles))->toBeGreaterThan(0)->toBeLessThan(count($pixels) / 2)
        ->and(array_values($teintes))->toBe(["\xFF\xFF\xFF"]);
});
