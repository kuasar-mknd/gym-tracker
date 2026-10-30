import { classes, decomposer } from './gabarits'

/**
 * Ce qu'un élément expose aux technologies d'assistance à une largeur donnée,
 * lu dans ses classes (#2120).
 *
 * jsdom n'applique aucune feuille de style, ni donc aucune requête média : un
 * `<h1>` caché à partir de 1 024 px (`bureau:hidden`) y reste présent, et
 * compter les titres du DOM ne dit rien de ce qu'un lecteur d'écran trouve sur
 * bureau. Les bascules du dépôt s'écrivent toutes par classes (`hidden
 * bureau:block`, `bureau:hidden`, `zonesSures.test.js` refuse les autres) :
 * leur requête se rejoue ici. Un élément `sr-only` reste exposé : il est caché
 * aux yeux, pas aux lecteurs d'écran.
 */

/** Les deux largeurs que la garde des titres vérifie : un téléphone debout, une fenêtre de bureau. */
export const LARGEURS = { telephone: 390, bureau: 1280 }

/** La largeur minimale de chaque variante de Tailwind, et de `bureau:` (`(width >= 64rem)` dans app.css). */
const SEUILS = { sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536, bureau: 1024 }

const AFFICHAGES = /^(?:block|inline|inline-block|flex|inline-flex|grid|inline-grid|table|contents|flow-root)$/

/**
 * Une liste de classes laisse-t-elle l'élément affiché à cette largeur ?
 * `hidden` le cache ; une variante de largeur atteinte qui pose un affichage
 * (`bureau:block`) le montre, ou le cache (`bureau:hidden`), la plus large
 * l'emportant. `invisible` le retire aussi de l'arbre d'accessibilité.
 *
 * @param {string} liste
 * @param {number} largeur
 * @returns {boolean}
 */
export const afficheA = (liste, largeur) => {
    const mots = classes(liste)

    if (mots.includes('invisible')) {
        return false
    }

    return mots
        .map(decomposer)
        .filter(({ variante }) => variante in SEUILS && SEUILS[variante] <= largeur)
        .filter(({ base }) => base === 'hidden' || AFFICHAGES.test(base))
        .sort((a, b) => SEUILS[a.variante] - SEUILS[b.variante])
        .reduce((affiche, { base }) => base !== 'hidden', !mots.includes('hidden'))
}

/**
 * Un élément RENDU est-il exposé à cette largeur ? Lui et chacun de ses
 * ancêtres doivent être affichés, et aucun ne doit être caché par
 * `aria-hidden="true"`, l'attribut `hidden` ou un `display: none` posé en
 * style (`v-show`).
 *
 * @param {Element} element
 * @param {number} largeur
 * @returns {boolean}
 */
export const exposeA = (element, largeur) => {
    for (let courant = element; courant !== null; courant = courant.parentElement) {
        if (
            courant.getAttribute('aria-hidden') === 'true' ||
            courant.hidden ||
            courant.style?.display === 'none' ||
            !afficheA(courant.getAttribute('class') ?? '', largeur)
        ) {
            return false
        }
    }

    return true
}

/**
 * Le texte d'un titre, un `<br>` comptant pour une espace : « Calculatrice<br />1RM »
 * se lit « Calculatrice 1RM », pas « Calculatrice1RM ».
 *
 * @param {Element} titre
 * @returns {string}
 */
const texteDuTitre = (titre) => {
    const morceaux = []
    const parcourir = (noeud) =>
        noeud.childNodes.forEach((enfant) => {
            if (enfant.nodeName === 'BR') {
                morceaux.push(' ')
            } else if (enfant.nodeType === enfant.TEXT_NODE) {
                morceaux.push(enfant.textContent)
            } else {
                parcourir(enfant)
            }
        })

    parcourir(titre)

    return morceaux.join('').replace(/\s+/g, ' ').trim()
}

/**
 * Les `<h1>` qu'un arbre rendu expose à chaque largeur.
 *
 * @param {Element} racine
 * @returns {{telephone: string[], bureau: string[]}} Le texte de chaque titre exposé.
 */
export const titresExposes = (racine) =>
    Object.fromEntries(
        Object.entries(LARGEURS).map(([nom, largeur]) => [
            nom,
            [...racine.querySelectorAll('h1')].filter((titre) => exposeA(titre, largeur)).map(texteDuTitre),
        ]),
    )
