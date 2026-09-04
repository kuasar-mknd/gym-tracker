/**
 * L'habillage commun des graphiques : infobulles, axes, grilles.
 *
 * Ces réglages étaient recopiés dans chacun des 47 graphiques. Le compte, avant
 * cette page : un même gris d'encre 82 fois, un gris de graduation 52 fois, un
 * troisième 26 fois — 160 des 326 couleurs écrites en dur ne décrivaient aucune
 * donnée, elles redécrivaient à chaque fois la même infobulle.
 *
 * Ce n'était donc pas un problème de couleurs mais de configuration absente :
 * il n'y avait aucun endroit où poser la réponse, alors elle a été reposée 47
 * fois, et elle a divergé — trois gris différents pour un seul rôle de texte
 * secondaire.
 */

import { jeton, jetonTransparent } from '@/Utils/couleurs'

/**
 * L'infobulle, dans le trait de l'accent qui porte le graphique.
 *
 * @param {{accent?: string, opaque?: boolean}} options
 * @returns {object}
 */
export function infobulle({ accent = 'accent-primary', opaque = false } = {}) {
    return {
        backgroundColor: jetonTransparent('surface-card', opaque ? 0.95 : 0.9),
        titleColor: jeton('text-main'),
        bodyColor: jeton('text-main'),
        borderColor: jetonTransparent(accent, 0.2),
        borderWidth: 1,
        padding: 12,
        cornerRadius: 12,
    }
}

/**
 * Les graduations d'un axe.
 *
 * @param {{taille?: number, gras?: boolean}} options
 * @returns {object}
 */
export function graduations({ taille = 10, gras = true } = {}) {
    return {
        color: jeton('text-muted'),
        font: { size: taille, weight: gras ? 'bold' : 'normal', family: 'sans-serif' },
    }
}

/**
 * Une grille discrète, dérivée du trait de la charte.
 *
 * @returns {object}
 */
export function grille() {
    return {
        color: jetonTransparent('border', 0.6),
    }
}

const estUnObjetSimple = (valeur) =>
    valeur !== null && typeof valeur === 'object' && Object.getPrototypeOf(valeur) === Object.prototype

/**
 * Les réglages d'un graphique posés sur ceux de base, branche par branche :
 * un objet se fusionne, tout le reste (tableau, fonction, valeur) remplace.
 *
 * @param {object} base
 * @param {object|null|undefined} ajouts
 * @returns {object}
 */
export function fusionner(base, ajouts) {
    const resultat = { ...base }

    for (const [clef, valeur] of Object.entries(ajouts ?? {})) {
        resultat[clef] =
            estUnObjetSimple(valeur) && estUnObjetSimple(base?.[clef]) ? fusionner(base[clef], valeur) : valeur
    }

    return resultat
}

/**
 * Un dégradé vertical entre deux jetons, pour un fond de barre ou d'aire.
 *
 * Rend `null` tant que Chart.js n'a pas mesuré sa zone de tracé — c'est son
 * protocole, pas une erreur : la fonction est rappelée à la mesure suivante.
 *
 * @param {object} context
 * @param {string} duBas
 * @param {string} duHaut
 * @param {{opaciteBasse?: number, opaciteHaute?: number}} options
 * @returns {CanvasGradient|null}
 */
export function degradeVertical(context, duBas, duHaut, { opaciteBasse = 1, opaciteHaute = 1 } = {}) {
    const { ctx, chartArea } = context.chart

    if (!chartArea) {
        return null
    }

    const degrade = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
    degrade.addColorStop(0, jetonTransparent(duBas, opaciteBasse))
    degrade.addColorStop(1, jetonTransparent(duHaut, opaciteHaute))

    return degrade
}

export const commonTooltipOptions = {
    get backgroundColor() {
        return jetonTransparent('surface-card', 0.9)
    },
    get titleColor() {
        return jeton('text-main')
    },
    get bodyColor() {
        return jeton('text-main')
    },
    padding: 12,
    cornerRadius: 12,
    borderWidth: 0,
}

export const volumeTooltipCallback = function (context) {
    let label = context.dataset.label || ''
    if (label) {
        label += ': '
    }
    if (context.parsed.y !== null) {
        label += context.parsed.y.toLocaleString() + ' kg'
    }
    return label
}
