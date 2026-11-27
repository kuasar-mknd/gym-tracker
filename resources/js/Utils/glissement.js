/**
 * La mécanique d'une rangée qu'on tire : des fonctions pures, sans DOM ni
 * horloge, que `SwipeableRow.vue` branche sur les évènements du doigt.
 */

/** Combien le doigt a parcouru au-delà du dernier cran : de plus en plus mollement, jamais plus que `dimension`. */
export const elastique = (depassement, dimension, coefficient = 0.55) =>
    (depassement * dimension * coefficient) / (dimension + coefficient * Math.abs(depassement))

/** Où l'élan, en pixels par seconde, mènerait encore la rangée si rien ne la retenait. */
export const projeter = (vitesse, taux = 0.998) => ((vitesse / 1000) * taux) / (1 - taux)

/**
 * La vitesse du doigt sur les 100 dernières millisecondes, en pixels par
 * seconde. `fin` est l'instant du relâchement : un doigt arrêté avant de lâcher
 * n'a plus de vitesse.
 *
 * @param {Array<{t: number, x: number}>} echantillons
 * @param {number} [fin]
 */
export const vitesseDuGeste = (echantillons, fin = echantillons.at(-1)?.t ?? 0) => {
    const recents = echantillons.filter((echantillon) => echantillon.t >= fin - 100)

    if (recents.length < 2) {
        return 0
    }

    const premier = recents[0]
    const dernier = recents.at(-1)

    return ((dernier.x - premier.x) / Math.max(1, dernier.t - premier.t)) * 1000
}

/**
 * Le cran où la rangée se pose : le plus proche de l'endroit où l'élan la
 * mènerait, parmi le cran d'où le doigt est parti et son voisin. Jamais d'un
 * côté à l'autre, si fort que le geste soit lancé.
 *
 * @param {{x: number, vitesse: number, crans: number[], cranDeDepart: number}} geste
 */
export const cibleAuRelachement = ({ x, vitesse, crans, cranDeDepart }) => {
    const ordonnes = [...crans].sort((a, b) => a - b)
    const rangDeDepart = ordonnes.indexOf(cranDeDepart)
    const permis = ordonnes.filter((_, rang) => Math.abs(rang - rangDeDepart) <= 1)
    const arrivee = x + projeter(vitesse)

    return permis.reduce((meilleur, cran) =>
        Math.abs(arrivee - cran) < Math.abs(arrivee - meilleur) ? cran : meilleur,
    )
}

/**
 * Un pas d'un ressort amorti, exact quel que soit `dt` (solution de l'équation,
 * pas une intégration) : la position et la vitesse de la rangée `dt` secondes
 * plus tard. `reponse` est la période propre en secondes, `amortissement` vaut 1
 * à l'amortissement critique, sans dépassement.
 *
 * @param {{x: number, v: number}} etat
 * @param {number} cible
 * @param {number} dt
 * @param {{reponse: number, amortissement: number}} reglage
 */
export const pasDuRessort = ({ x, v }, cible, dt, { reponse, amortissement }) => {
    const pulsation = (2 * Math.PI) / reponse
    const ecart = x - cible
    const decroissance = Math.exp(-Math.min(amortissement, 1) * pulsation * dt)

    if (amortissement >= 1) {
        const reste = v + pulsation * ecart

        return {
            x: cible + decroissance * (ecart + reste * dt),
            v: decroissance * (v - pulsation * reste * dt),
        }
    }

    const propre = pulsation * Math.sqrt(1 - amortissement ** 2)
    const frein = amortissement * pulsation
    const a = ecart
    const b = (v + frein * ecart) / propre
    const cosinus = Math.cos(propre * dt)
    const sinus = Math.sin(propre * dt)

    return {
        x: cible + decroissance * (a * cosinus + b * sinus),
        v: decroissance * ((propre * b - frein * a) * cosinus - (frein * b + propre * a) * sinus),
    }
}
