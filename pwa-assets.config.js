import { readFileSync } from 'node:fs'

/**
 * La recette des icônes de l'application (#1850).
 *
 * Les PNG de public/ sont versionnés : rien ne les reconstruit, ni Vite ni
 * l'image Docker. On ne les régénère qu'après avoir retouché le dessin de
 * public/logo.svg ou de public/badge.svg, ou les couleurs de la charte, par
 * deux commandes lancées depuis la racine :
 *
 *     npx -y @vite-pwa/assets-generator@2.0.0
 *     npx -y @vite-pwa/assets-generator@2.0.0 public/badge.svg
 *
 * Le générateur n'est pas une dépendance du dépôt : il tire sharp, un binaire
 * natif, pour un usage d'une fois. Sa version est épinglée, et deux exécutions
 * de la recette ont rendu des fichiers identiques à l'octet. vite-plugin-pwa ne lit pas ce fichier :
 * il faudrait lui passer l'option `pwaAssets`, que vite.config.js ne lui
 * passe pas.
 *
 * tests/Feature/IconesDeLApplicationTest.php vérifie le résultat : tailles,
 * opacité de l'icône iOS, couleur de son fond, badge blanc sur transparent.
 */

/**
 * Le fond du logo, lu dans la charte plutôt que recopié.
 *
 * C'est le fond de l'utilitaire `accent-fill`, celui que public/logo.svg porte
 * en clair. Il comble ici les coins arrondis du logo sur les deux icônes
 * opaques : même résultat qu'une source carrée pleine, sans second dessin à
 * tenir.
 */
const fondDuLogo = readFileSync('resources/css/app.css', 'utf8').match(
    /--color-accent-primary-fill:\s*(#[0-9a-f]{6});/i,
)[1]

/**
 * PNG sans palette. La quantification par défaut du générateur (qualité 60)
 * réduit l'icône iOS à huit couleurs, et le bord des tracés tombe en escalier.
 * Le prix : 3,9 Kio au lieu de 0,9 pour l'icône iOS, 13 au lieu de 3,5 pour la
 * masquable.
 */
const png = { compressionLevel: 9, palette: false }

/**
 * Le jeu de minimal-2023, à deux réglages près.
 *
 * Le générateur réduit par défaut le logo à 70 % des icônes iOS et masquable,
 * sur fond blanc : il sortirait rapetissé au milieu d'un carré blanc. Sans
 * marge, son dessin tient dans un cercle de 140 px de rayon sur 512, quand la
 * zone sûre d'une icône masquable en fait 205 : le masque du lanceur ne rogne
 * que du fond.
 */
const icones = {
    transparent: {
        sizes: [64, 192, 512],
        favicons: [[48, 'favicon.ico']],
    },
    maskable: {
        sizes: [512],
        padding: 0,
        resizeOptions: { fit: 'contain', background: fondDuLogo },
    },
    apple: {
        sizes: [180],
        padding: 0,
        resizeOptions: { fit: 'contain', background: fondDuLogo },
    },
    png,
}

/**
 * Le badge des notifications, tiré de public/badge.svg : un seul PNG, sans
 * marge puisque le cadre du SVG porte déjà la sienne.
 */
const badge = {
    transparent: { sizes: [96], padding: 0 },
    maskable: { sizes: [] },
    apple: { sizes: [] },
    png,
    assetName: (_type, taille) => `badge-${taille.width}x${taille.height}.png`,
}

/**
 * Le générateur applique un seul jeu à toutes ses images et écrit chaque PNG à
 * côté de sa source : le badge a donc sa propre commande, qui nomme son image
 * et que ce fichier reconnaît.
 */
const pourLeBadge = process.argv.some((argument) => argument.endsWith('badge.svg'))

export default {
    headLinkOptions: { preset: '2023' },
    preset: pourLeBadge ? badge : icones,
    images: ['public/logo.svg'],
}
