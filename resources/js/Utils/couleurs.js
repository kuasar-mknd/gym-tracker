/**
 * Lire la charte depuis le JavaScript.
 *
 * Les graphiques dessinent sur canvas : ils ne peuvent pas porter de classes,
 * donc ils ont besoin de valeurs. Ils en écrivaient 230 en dur, dont
 * un magenta commenté `magenta-pure` et un vert commenté `neon-green` — ce
 * dernier ne correspondant à aucun jeton, puisque le vert d'état est bien plus
 * acide que ce qui était écrit. Deux verts différents portaient le même nom, et
 * rien ne pouvait s'en apercevoir.
 *
 * Le principe : **le JavaScript porte les noms, le CSS porte les valeurs.**
 * Aucune valeur n'est recopiée ici, et `LaCharteEstLueParLeJsTest` vérifie que
 * chaque nom cité a bien sa déclaration dans `app.css`.
 */

/**
 * Les catégories d'exercice et leur jeton.
 *
 * Les clés sont celles d'`EXERCISE_CATEGORIES`, aux deux exceptions près que le
 * code portait déjà : `Core` et `Autres` n'y figurent pas mais reçoivent une
 * couleur, l'une parce que les données historiques l'emploient, l'autre comme
 * repli.
 */
export const JETON_PAR_CATEGORIE = {
    Pectoraux: 'category-chest',
    Dos: 'category-back',
    Épaules: 'category-shoulders',
    Bras: 'category-arms',
    Jambes: 'category-legs',
    Core: 'category-core',
    Cardio: 'category-cardio',
    Abdominaux: 'category-core',
    Autres: 'category-other',
}

/**
 * Le cache est volontaire : `getComputedStyle` force un recalcul de style, et
 * un graphique qui redessine appellerait cette fonction à chaque image.
 *
 * Il est vidé par `oublierLesJetons()`, dont les tests se servent quand ils
 * changent les variables sous les pieds du cache.
 */
const cache = new Map()

/**
 * La valeur d'un jeton de la charte, par son nom sans le préfixe `--color-`.
 *
 * Rend une chaîne vide quand la feuille n'est pas chargée — c'est le cas sous
 * jsdom, où `vitest.setup.js` pose les variables dont les tests ont besoin.
 * Mieux vaut une couleur absente qu'une valeur de repli écrite ici : ce serait
 * exactement la recopie que ce module existe pour supprimer.
 *
 * @param {string} nom
 * @returns {string}
 */
export function jeton(nom) {
    if (cache.has(nom)) {
        return cache.get(nom)
    }

    const valeur = getComputedStyle(document.documentElement).getPropertyValue(`--color-${nom}`).trim()

    cache.set(nom, valeur)

    return valeur
}

/** @returns {void} */
export function oublierLesJetons() {
    cache.clear()
}

/**
 * La couleur d'une catégorie d'exercice, repli compris.
 *
 * @param {string} categorie
 * @returns {string}
 */
export function couleurDeCategorie(categorie) {
    return jeton(JETON_PAR_CATEGORIE[categorie] ?? 'category-other')
}

/**
 * Un jeton avec une opacité, prêt pour un canvas.
 *
 * Chart.js dessine sur canvas et accepte n'importe quelle couleur CSS, mais pas
 * une variable : `var(--color-…)` n'a aucun sens pour un contexte 2D. Il faut
 * donc une valeur — et c'est précisément là que 194 `rgba()` littéraux étaient
 * apparus, dont un `rgba()` orange qui n'était que le jeton principal
 * retranscrit à la main, canal par canal.
 *
 * Cette fonction CALCULE à partir du jeton au lieu de le recopier. Change
 * l'orange dans `app.css`, et les lueurs, les bordures d'infobulle et les
 * dégradés suivent sans qu'on touche à un seul graphique.
 *
 * @param {string} nom
 * @param {number} opacite
 * @returns {string}
 */
export function jetonTransparent(nom, opacite) {
    const valeur = jeton(nom)

    if (valeur === '') {
        return 'transparent'
    }

    const canaux = canauxDe(valeur)

    return canaux === null ? valeur : `rgba(${canaux[0]}, ${canaux[1]}, ${canaux[2]}, ${opacite})`
}

/**
 * Les trois canaux d'une couleur calculée, quelle que soit sa notation.
 *
 * `getComputedStyle` rend ce que le navigateur a résolu : la notation
 * hexadécimale sous jsdom, `rgb(…)` sous Chromium, et `color(srgb …)` dans
 * certains cas. Les trois se lisent ici plutôt que de supposer un moteur.
 *
 * @param {string} valeur
 * @returns {[number, number, number]|null}
 */
function canauxDe(valeur) {
    const court = valeur.match(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i)
    if (court !== null) {
        return [1, 2, 3].map((i) => parseInt(court[i] + court[i], 16))
    }

    const long = valeur.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i)
    if (long !== null) {
        return [1, 2, 3].map((i) => parseInt(long[i], 16))
    }

    const fonctionnelle = valeur.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i)
    if (fonctionnelle !== null) {
        return [1, 2, 3].map((i) => Math.round(Number(fonctionnelle[i])))
    }

    return null
}
