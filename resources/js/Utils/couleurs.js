/**
 * Lire la charte depuis le JavaScript.
 *
 * Les graphiques dessinent sur canvas : ils ne peuvent pas porter de classes,
 * donc ils ont besoin de valeurs. Ils en écrivaient 230 en dur, dont
 * `#F5009B // magenta-pure` et `#00FF66 // neon-green` — cette dernière ne
 * correspondant à aucun jeton, le vrai valant `#CCFF00`. Deux verts différents
 * portaient le même nom, et rien ne pouvait s'en apercevoir.
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
