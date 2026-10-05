/**
 * Un seul format par grandeur, pour toute l'application.
 *
 * Le même poids s'écrivait « 78,4 kg » sur l'accueil et « 78.40 kg » sur
 * Mesures, et le volume du mois affichait « 15,750 kg » — qui se lit quinze
 * virgule sept cent cinquante en français, soit mille fois moins que la
 * réalité (#1787). `toFixed()` rend toujours un point décimal, quelle que soit
 * la langue de la page ; `toLocaleString()` sans langue suit celle du
 * navigateur, donc l'anglais chez qui ne l'a pas changée.
 *
 * La langue est suisse : le millier s'y sépare par une apostrophe (15'750).
 * Les dates, elles, restent en `fr-FR` — l'ordre jour/mois/année : c'est
 * `LANGUE_DES_DATES` de `Utils/date.js`, et `tests/js/conventions/dates.test.js`
 * refuse une date mise en forme sans langue.
 */
export const LANGUE = 'fr-CH'

const formateur = (decimales, decimalesMin = 0) =>
    new Intl.NumberFormat(LANGUE, {
        minimumFractionDigits: decimalesMin,
        maximumFractionDigits: decimales,
    })

/**
 * Le serveur rend ses décimales en chaîne (`weight` vaut `"78.40"`), et une
 * chaîne n'a pas de `toFixed`. Tout ce qui se lit comme un nombre fini en
 * devient un ; le reste vaut `null`, et s'affichera comme un tiret.
 */
const enNombre = (valeur) => {
    if (typeof valeur === 'number') {
        return Number.isFinite(valeur) ? valeur : null
    }

    if (typeof valeur === 'string' && valeur.trim() !== '') {
        const converti = Number(valeur)

        return Number.isFinite(converti) ? converti : null
    }

    return null
}

/**
 * Un nombre, avec au plus `decimales` décimales et ses milliers séparés.
 *
 * Rend `'—'` pour ce qui n'est pas un nombre : un `null` venu du serveur ne
 * doit jamais s'afficher « NaN ».
 */
export const nombre = (valeur, decimales = 1, decimalesMin = 0) =>
    enNombre(valeur) === null ? '—' : formateur(decimales, decimalesMin).format(enNombre(valeur))

/** Un poids, une décimale : `78,4 kg`. */
export const poids = (kilos, decimales = 1) => (enNombre(kilos) === null ? '—' : `${nombre(kilos, decimales)} kg`)

/** Un volume, sans décimale et milliers séparés : `15'750 kg`. */
export const volume = (kilos) => (enNombre(kilos) === null ? '—' : `${nombre(kilos, 0)} kg`)

/** Une variation, toujours signée : `+0,3 kg`, `−1,2 kg`. */
export const variation = (delta, unite = 'kg', decimales = 1) => {
    const valeur = enNombre(delta)

    if (valeur === null) {
        return '—'
    }

    const signe = valeur > 0 ? '+' : ''

    return `${signe}${nombre(delta, decimales)}${unite ? ` ${unite}` : ''}`
}

/** Un pourcentage : `62,5 %`. */
export const pourcentage = (valeur, decimales = 1) =>
    enNombre(valeur) === null ? '—' : `${nombre(valeur, decimales)} %`

/** Un compte : `12`, jamais `12,0`. */
export const entier = (valeur) => nombre(valeur, 0)

/**
 * Un compte suivi de son nom, accordé : `1 série`, `4 séries`.
 *
 * Plusieurs compteurs collaient un pluriel fixe à un nombre qui vaut souvent
 * 1 (« 1 séries », « 1 exos », « 1 produits suivis ») et un autre un singulier
 * fixe (« 4 exo ») (#1980). En français, le singulier vaut jusqu'à 2
 * exclu : `0 série`, `1 série`, `2 séries`. L'accord se fait sur le nombre
 * affiché, arrondi à l'unité comme par `entier()`.
 *
 * @param {number|string|null|undefined} valeur
 * @param {string} singulier
 * @param {string} pluriel
 * @returns {string} Le compte et son nom, ou `'—'` pour ce qui n'est pas un nombre.
 */
export const compte = (valeur, singulier, pluriel) => {
    const n = enNombre(valeur)

    return n === null ? '—' : `${entier(n)} ${Math.abs(Math.round(n)) < 2 ? singulier : pluriel}`
}
