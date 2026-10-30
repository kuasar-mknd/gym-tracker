import { TAILLES as TAILLES_DES_ICONES } from '@/Components/UI/GlassIcon.vue'

/**
 * L'arithmétique des cibles tactiles, que `touchTargets.test.js` applique au
 * source et que les tests de page appliquent au rendu (#2116).
 *
 * Écrite d'abord dans la garde, où elle ne lisait que des `<button>` : un lien
 * réduit à une icône restait hors de portée, et cinq d'entre eux tenaient entre
 * 36 et 40 px, dont le retour de l'en-tête mobile sur seize pages.
 */

/** La charte fixe `--spacing-touch` à 44 px, comme les WCAG 2.5.8 (AAA). */
export const CIBLE = 44

/** Le carré d'une ligature Material Symbols à sa taille par défaut (`font-size: 24px` dans app.css). */
export const ICONE = 24

/**
 * La taille de police des utilitaires `text-*` de Tailwind 4, en pixels, et
 * leur hauteur de ligne : une ligature fait la largeur de sa police, et la
 * hauteur de ligne de l'utilitaire l'emporte sur le `line-height: 1` de
 * `.material-symbols-outlined`, émis avant lui dans la feuille.
 */
const TEXTES = {
    'text-2xs': { police: 10, ligne: 14 },
    'text-xs': { police: 12, ligne: 16 },
    'text-sm': { police: 14, ligne: 20 },
    'text-base': { police: 16, ligne: 24 },
    'text-lg': { police: 18, ligne: 28 },
    'text-xl': { police: 20, ligne: 28 },
    'text-2xl': { police: 24, ligne: 32 },
    'text-3xl': { police: 30, ligne: 36 },
    'text-4xl': { police: 36, ligne: 40 },
    'text-5xl': { police: 48, ligne: 48 },
    'text-6xl': { police: 60, ligne: 60 },
}

export const echelle = (valeur) => (valeur === 'px' ? 1 : Number(valeur) * 4)

const tailles = (mots, motif) =>
    mots
        .map((mot) => motif.exec(mot))
        .filter(Boolean)
        .map((trouve) => echelle(trouve[1]))

/**
 * Le carré qu'occupe l'icône d'un fragment de gabarit.
 *
 * Une ligature vaut 24 px ; un `<svg>`, lui, DÉCLARE sa taille — `h-4` en fait
 * un carré de 16. Supposer 24 pour les deux surestimait la cible de chaque
 * bouton SVG, donc sous-estimait le défaut : treize d'entre eux étaient sous la
 * cible, et la garde n'en voyait aucun. `GlassIcon` déclare la sienne par
 * `size` : `sm` est un `text-xl`, une ligature de 20 px, et le « Modifier »
 * d'un modèle comptait 20 + 2 × 8 = 36 px de large.
 *
 * @param {string} corps
 * @returns {number}
 */
export const icone = (corps) => {
    const svg = /<svg[^>]*class="([^"]*)"/.exec(corps)

    if (svg !== null) {
        const declarees = tailles(svg[1].split(/\s+/), /^(?:size|[hw])-(\d+(?:\.5)?|px)$/)

        return declarees.length > 0 ? Math.min(...declarees) : ICONE
    }

    const glassIcon = /<GlassIcon\b((?:[^>"']|"[^"]*"|'[^']*')*)>/.exec(corps)

    if (glassIcon !== null) {
        const taille = /(?:^|\s)size="([^"]+)"/.exec(glassIcon[1])?.[1] ?? 'md'

        return TEXTES[TAILLES_DES_ICONES[taille]]?.police ?? ICONE
    }

    return ICONE
}

/**
 * La plus petite dimension que ce contrôle offre au doigt, en pixels.
 *
 * Trois façons de l'obtenir cohabitent dans le dépôt, et elles s'additionnent :
 * une taille explicite (`size-11`), un rembourrage autour de l'icône (`p-2`), et
 * un pseudo-élément qui déborde sans pousser les voisins
 * (`before:-inset-2.5`) — cette dernière étant la seule utilisable quand le
 * contrôle doit rester visuellement petit.
 *
 * @param {string} classes
 * @param {number} tailleIcone
 * @returns {number}
 */
export const cible = (classes, tailleIcone) => {
    const mots = classes.split(/\s+/)

    if (mots.includes('min-h-touch') || mots.includes('min-w-touch')) {
        return CIBLE
    }

    const explicite = tailles(mots, /^(?:size|[hw])-(\d+(?:\.5)?|px)$/)
    const rembourrage = tailles(mots, /^p-(\d+(?:\.5)?|px)$/)
    const debord = tailles(mots, /^before:-inset-(\d+(?:\.5)?|px)$/)

    const boite = explicite.length > 0 ? Math.min(...explicite) : tailleIcone + 2 * Math.max(0, ...rembourrage, 0)

    return boite + 2 * Math.max(0, ...debord, 0)
}

/**
 * Les contrôles dont la taille ne se lit PAS dans leurs classes.
 *
 * Deux formes, toutes deux légitimes : celui qui remplit son parent (`h-full`,
 * la trappe de suppression d'une ligne qu'on fait glisser) et celui qui porte un
 * utilitaire nommé de la charte (`glass-nav-fab`, qui vaut 72 px). Les compter à
 * 24 px serait faux ; les compter comme conformes serait un mensonge. Ils sont
 * écartés de la mesure, et cette note est là pour que personne ne croie la
 * mesure exhaustive.
 *
 * @param {string} classes
 * @returns {boolean}
 */
export const mesurable = (classes) => !/\b(?:[hw]-full|size-full|inset-0|flex-1|glass-[a-z-]+)\b/.test(classes)

/**
 * La même arithmétique sur un contrôle RENDU, réduit à une icône : ses classes,
 * et la taille de l'icône qu'il contient, lue sur l'icône rendue (`text-xl`
 * pour `GlassIcon size="sm"`, les classes d'un `<svg>`).
 *
 * @param {Element} controle
 * @returns {number}
 */
export const cibleDuRendu = (controle) => {
    const symbole = controle.querySelector('svg, .material-symbols-outlined')
    const classesDuSymbole = (symbole?.getAttribute('class') ?? '').split(/\s+/)
    const taille =
        symbole?.tagName.toLowerCase() === 'svg'
            ? icone(`<svg class="${classesDuSymbole.join(' ')}">`)
            : (classesDuSymbole.map((classe) => TEXTES[classe]?.police).find(Boolean) ?? ICONE)

    return cible(controle.getAttribute('class') ?? '', taille)
}

/**
 * La hauteur qu'un contrôle RENDU offre au doigt, en pixels : un bouton à
 * texte, dont la largeur suit son libellé et dont la hauteur est la seule à
 * pouvoir manquer. `min-h-touch` la porte à 44 px ; sans hauteur déclarée, elle
 * vaut la hauteur de ligne de son texte et son rembourrage vertical, plus ce
 * qu'un pseudo-élément déborde. Un « Déconnexion » en `text-xs` sans
 * rembourrage tenait en 16 px.
 *
 * @param {Element} controle
 * @returns {number}
 */
export const hauteurDuRendu = (controle) => {
    const mots = (controle.getAttribute('class') ?? '').split(/\s+/)

    if (mots.includes('min-h-touch')) {
        return CIBLE
    }

    const declarees = tailles(mots, /^(?:min-h|h|size)-(\d+(?:\.5)?|px)$/)
    const ligne = mots.map((mot) => TEXTES[mot]?.ligne).find(Boolean) ?? TEXTES['text-base'].ligne
    const rembourrage = Math.max(0, ...tailles(mots, /^py?-(\d+(?:\.5)?|px)$/))
    const debord = Math.max(0, ...tailles(mots, /^before:-inset(?:-y)?-(\d+(?:\.5)?|px)$/))
    const boite = declarees.length > 0 ? Math.max(...declarees) : ligne + 2 * rembourrage

    return boite + 2 * debord
}
