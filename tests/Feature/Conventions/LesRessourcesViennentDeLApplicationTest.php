<?php

declare(strict_types=1);

/*
 * Ce que le navigateur charge pour afficher une page, l'application le sert
 * elle-même (#2051).
 *
 * Les pages de connexion et d'inscription prenaient leurs trois logos chez un
 * site d'icônes tiers. Chaque visite lui envoyait l'adresse IP, le navigateur
 * et la page d'origine de la personne, avant qu'elle ait choisi quoi que ce
 * soit ; la CSP ouvrait `img-src` à un domaine que personne ici ne contrôle ;
 * et le worker ne gardait jamais ces images.
 *
 * Ce contrôle refuse donc toute adresse d'une autre origine, absolue
 * (`https://…`) ou sans protocole (`//hôte/…`), dans ce que l'application sert
 * au navigateur : composants, scripts et feuilles de `resources/js` et
 * `resources/css`, gabarits et feuilles de `resources/views`, et les SVG et le
 * manifeste posés à la racine de `public/`. Une image, mais
 * aussi un script, une feuille, une police, un `url()` ou une chaîne du
 * JavaScript qui deviendrait une requête. Restent permis, parce que rien ne
 * s'y télécharge : le `href` d'un lien `<a>`, que seul un clic suit ; un
 * espace de noms XML et la DTD d'un `<!DOCTYPE>`, qui sont des identifiants ;
 * un commentaire.
 *
 * resources/views/docs en est écarté : la page de la charte s'y écrit pour
 * docs/ (`php artisan charte:publier`), et l'application ne la sert jamais.
 */

use Symfony\Component\Finder\Finder;

/**
 * Les espaces de noms XML : des identifiants que rien ne télécharge.
 *
 * @return list<string>
 */
function ressourcesTiercesEspacesDeNoms(): array
{
    return [
        'http://www.w3.org/2000/svg',
        'http://www.w3.org/1999/xlink',
        'http://www.w3.org/1999/xhtml',
        'http://www.w3.org/1998/Math/MathML',
    ];
}

/**
 * Les plages qu'un motif couvre dans un texte, en octets, décalées de
 * `$depart` : début inclus, fin exclue.
 *
 * @return list<array{0: int, 1: int}>
 */
function ressourcesTiercesPlagesDuMotif(string $motif, string $texte, int $depart = 0): array
{
    if (preg_match_all($motif, $texte, $trouvees, PREG_OFFSET_CAPTURE) === false) {
        throw new RuntimeException("Le motif {$motif} est invalide.");
    }

    return array_map(
        static fn (array $trouvee): array => [$depart + $trouvee[1], $depart + $trouvee[1] + strlen($trouvee[0])],
        $trouvees[0],
    );
}

/**
 * Les blocs de code d'un source, chacun avec sa position et sa langue : le
 * fichier entier pour un `.js` ou un `.css`, le contenu de chaque `<script>` et
 * de chaque `<style>` ailleurs.
 *
 * Les commentaires `/* … *\/` et `//` ne se reconnaissent que là : dans un
 * gabarit, `accept="image/*"` ouvrirait sinon un commentaire qui cacherait la
 * suite du fichier, et deux barres obliques y ouvrent une adresse sans
 * protocole.
 *
 * @return list<array{position: int, texte: string, javascript: bool}>
 */
function ressourcesTiercesBlocsDeCode(string $source, string $chemin): array
{
    if (str_ends_with($chemin, '.js') || str_ends_with($chemin, '.css')) {
        return [['position' => 0, 'texte' => $source, 'javascript' => str_ends_with($chemin, '.js')]];
    }

    preg_match_all('~<(script|style)\b[^>]*>(.*?)</\1>~is', $source, $blocs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    return array_map(
        static fn (array $bloc): array => [
            'position' => $bloc[2][1],
            'texte' => $bloc[2][0],
            'javascript' => strtolower($bloc[1][0]) === 'script',
        ],
        $blocs,
    );
}

/**
 * Les plages des commentaires d'un bloc de code, décalées de sa position.
 *
 * Les littéraux de chaîne se lisent d'abord, et seuls les commentaires
 * restent : `'image/*'` ou `content: "/*"` n'ouvrent pas un commentaire qui
 * cacherait le code jusqu'au prochain `*\/`, ni `' // '` un commentaire qui
 * cacherait la fin de sa ligne. Le gabarit littéral (`` `…` ``) et le
 * commentaire `//` n'existent qu'en JavaScript.
 *
 * @param  array{position: int, texte: string, javascript: bool}  $bloc
 * @return list<array{0: int, 1: int}>
 */
function ressourcesTiercesCommentairesDuBloc(array $bloc): array
{
    $chaines = ['\'(?:\\\\.|[^\'\\\\\n])*\'', '"(?:\\\\.|[^"\\\\\n])*"'];
    $commentaire = '(/\*.*?\*/)';

    if ($bloc['javascript']) {
        $chaines[] = '`(?:\\\\.|[^`\\\\])*`';
        $commentaire = '(/\*.*?\*/|//[^\n]*)';
    }

    preg_match_all('~'.implode('|', [...$chaines, $commentaire]).'~s', $bloc['texte'], $trouvees, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $plages = [];

    foreach ($trouvees as $trouvee) {
        if (isset($trouvee[1]) && $trouvee[1][1] >= 0) {
            $debut = $bloc['position'] + $trouvee[1][1];
            $plages[] = [$debut, $debut + strlen($trouvee[1][0])];
        }
    }

    return $plages;
}

/**
 * Les plages d'un source où une adresse ne fait rien charger : les
 * commentaires (HTML et Blade partout, CSS et JavaScript dans le code), le
 * `<!DOCTYPE>`, que les gabarits de courriel écrivent avec l'adresse de leur
 * DTD, et la valeur du `href` d'un lien `<a>`.
 *
 * @return list<array{0: int, 1: int}>
 */
function ressourcesTiercesPlagesInertes(string $source, string $chemin): array
{
    $plages = [
        ...ressourcesTiercesPlagesDuMotif('~<!--.*?-->~s', $source),
        ...ressourcesTiercesPlagesDuMotif('~\{\{--.*?--\}\}~s', $source),
        ...ressourcesTiercesPlagesDuMotif('~<!DOCTYPE[^>]*>~i', $source),
    ];

    foreach (ressourcesTiercesBlocsDeCode($source, $chemin) as $bloc) {
        array_push($plages, ...ressourcesTiercesCommentairesDuBloc($bloc));
    }

    preg_match_all('~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $source, $liens, PREG_OFFSET_CAPTURE);

    foreach ($liens[0] as [$balise, $debut]) {
        preg_match_all('~\s(?::|v-bind:)?href\s*=\s*("[^"]*"|\'[^\']*\')~i', $balise, $cibles, PREG_OFFSET_CAPTURE);

        foreach ($cibles[1] as [$valeur, $position]) {
            $plages[] = [$debut + $position, $debut + $position + strlen($valeur)];
        }
    }

    return $plages;
}

/**
 * Les adresses d'une autre origine qu'un source ferait charger, chacune
 * précédée de sa ligne : `113 : https://…`.
 *
 * Une adresse absolue, même réduite à son protocole (`'https://' + hote`), ou
 * sans protocole (`//hote.example/…`) après un guillemet, une parenthèse, un
 * `=`, une virgule ou une espace : le deuxième candidat d'un `srcset` suit la
 * virgule du premier. Le nom d'hôte doit toucher les deux barres obliques, si
 * bien qu'un commentaire `// voir …` n'en est pas une.
 *
 * @return list<string>
 */
function ressourcesTiercesDans(string $source, string $chemin): array
{
    $inertes = ressourcesTiercesPlagesInertes($source, $chemin);
    $adresse = '~\bhttps?://[^\s"\'`)<>]*|(?<=["\'`(=,\s])//[a-z0-9][a-z0-9.-]*\.[a-z]{2,}[^\s"\'`)<>]*~i';

    preg_match_all($adresse, $source, $adresses, PREG_OFFSET_CAPTURE);

    $trouvees = [];

    foreach ($adresses[0] as [$texte, $position]) {
        $inerte = array_any($inertes, static fn (array $plage): bool => $position >= $plage[0] && $position < $plage[1]);

        if ($inerte || in_array($texte, ressourcesTiercesEspacesDeNoms(), true)) {
            continue;
        }

        $trouvees[] = sprintf('%d : %s', substr_count($source, "\n", 0, $position) + 1, $texte);
    }

    return $trouvees;
}

/**
 * Ce que l'application sert au navigateur, ou dont elle tire ce qu'elle sert.
 *
 * @return array<string, string> Chemin relatif au dépôt, puis contenu.
 */
function ressourcesTiercesFichiersServis(): array
{
    $ressources = Finder::create()
        ->files()
        ->in([resource_path('js'), resource_path('css'), resource_path('views')])
        ->name(['*.vue', '*.js', '*.css', '*.blade.php'])
        ->sortByName();

    $racineDePublic = Finder::create()
        ->files()
        ->in(public_path())
        ->depth(0)
        ->name(['*.svg', '*.webmanifest'])
        ->sortByName();

    $sources = [];

    foreach ([...$ressources, ...$racineDePublic] as $fichier) {
        $chemin = str_replace(base_path().'/', '', $fichier->getPathname());

        if (! str_starts_with($chemin, 'resources/views/docs/')) {
            $sources[$chemin] = $fichier->getContents();
        }
    }

    return $sources;
}

it('ne fait charger au navigateur aucune ressource d’une autre origine', function (): void {
    $fichiers = ressourcesTiercesFichiersServis();

    expect(array_keys($fichiers))->toContain(
        'resources/js/Pages/Auth/Login.vue',
        'resources/js/Components/Marques/LogoGoogle.vue',
        'resources/js/sw/pageHorsLigne.js',
        'resources/css/app.css',
        'resources/views/app.blade.php',
        'public/logo.svg',
        'public/manifest.webmanifest',
    );

    $trouvees = [];

    foreach ($fichiers as $chemin => $source) {
        foreach (ressourcesTiercesDans($source, $chemin) as $ligneEtAdresse) {
            $trouvees[] = "{$chemin}:{$ligneEtAdresse}";
        }
    }

    expect($trouvees)->toBe([], sprintf(
        "Ces fichiers font charger au navigateur une ressource d'une autre origine :\n  %s\n\n"
        ."Chaque chargement donne à ce site l'adresse IP, le navigateur et la page de la personne, "
        .'la CSP devrait lui ouvrir sa porte, et le worker ne le garde pas hors ligne. Servir le '
        .'fichier depuis le dépôt, comme les polices, ou dessiner l\'image dans un composant, comme '
        .'les logos des fournisseurs de connexion (resources/js/Components/Marques, #2051).',
        implode("\n  ", $trouvees),
    ));
});

it('reconnaît encore une ressource d’une autre origine, et laisse passer ce qui ne charge rien', function (): void {
    $composant = <<<'VUE'
        <template>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"></svg>
            <!-- l'ancien logo venait de https://icones.example.org/logo.svg -->
            <a href="https://example.org/aide">Aide</a>
            <a class="lien" :href="'https://example.org/conditions'">Conditions</a>
            <img src="https://icones.example.org/google.svg" alt="" />
            <img :src="'https://icones.example.org/github.svg'" alt="" />
            <source srcset="//cdn.example.org/fond.webp" />
            <div style="background-image: url(https://cdn.example.org/motif.png)"></div>
            <Link href="https://example.org/xhr">Inertia passe par une requête</Link>
            <input type="file" accept="image/*" />
            <img src="https://icones.example.org/apple.svg" alt="" />
            <img srcset="/build/petit.png 1x, //cdn.example.org/grand.png 2x" alt="" />
        </template>

        <script setup>
        // voir https://example.org/documentation
        /** et https://example.org/ailleurs */
        const image = new Image()
        image.src = 'https://cdn.example.org/pixel.gif'
        const hote = 'https://' + domaine
        const ACCEPTES = 'image/*'
        image.src = 'https://pixel.example.org/suivi.gif'
        /** Fin du script. */
        </script>

        <style>
        /* la police venait de https://polices.example.org */
        @import url('https://polices.example.org/police.css');
        </style>
        VUE;

    expect(ressourcesTiercesDans($composant, 'Echantillon.vue'))->toBe([
        '6 : https://icones.example.org/google.svg',
        '7 : https://icones.example.org/github.svg',
        '8 : //cdn.example.org/fond.webp',
        '9 : https://cdn.example.org/motif.png',
        '10 : https://example.org/xhr',
        '12 : https://icones.example.org/apple.svg',
        '13 : //cdn.example.org/grand.png',
        '20 : https://cdn.example.org/pixel.gif',
        '21 : https://',
        '23 : https://pixel.example.org/suivi.gif',
        '29 : https://polices.example.org/police.css',
    ]);

    $gabarit = <<<'BLADE'
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        {{-- la police venait de https://polices.example.org --}}
        <link rel="stylesheet" href="https://polices.example.org/police.css">
        <a href="https://example.org">Accueil</a>
        BLADE;

    expect(ressourcesTiercesDans($gabarit, 'gabarit.blade.php'))->toBe(['3 : https://polices.example.org/police.css']);

    $script = <<<'JS'
        // https://example.org/explication
        const chemin = '//pas-un-commentaire.example.org/script.js'
        export const API = 'https://api.example.org' // https://example.org/raison
        export const TYPES = ['image/*', 'video/*']
        fetch('https://api.example.org/suivi')
        const separateur = ' // '; fetch("https://api.example.org/ligne") // https://example.org/fin
        /** Fin. */
        JS;

    expect(ressourcesTiercesDans($script, 'module.js'))->toBe([
        '2 : //pas-un-commentaire.example.org/script.js',
        '3 : https://api.example.org',
        '5 : https://api.example.org/suivi',
        '6 : https://api.example.org/ligne',
    ]);

    $feuille = <<<'CSS'
        /* https://example.org/source-de-la-regle */
        .fond { background: url("//cdn.example.org/fond.png"); }
        .icone::before { content: "/*"; background: url(https://cdn.example.org/icone.png); }
        /* Fin. */
        CSS;

    expect(ressourcesTiercesDans($feuille, 'feuille.css'))->toBe([
        '2 : //cdn.example.org/fond.png',
        '3 : https://cdn.example.org/icone.png',
    ]);
});
