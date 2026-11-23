/**
 * Ce que les anneaux du tableau de bord ont en commun.
 *
 * Les deux anneaux du tableau de bord portaient chacun leur copie des options. Elles avaient dérivé :
 * polices différentes, `layout.padding` sur un seul des deux, bordures
 * différentes. D'où deux cercles de tailles visiblement différentes — mesurés
 * à 172 px et 151 px de diamètre sur un écran de 375 px (#1316).
 *
 * Une seule source, donc, et la dérive n'a plus où se loger.
 */

import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { LANGUE } from '@/Utils/nombre'

/**
 * Les options communes aux anneaux du tableau de bord.
 *
 * La légende passe **en bas**. À droite, elle mangeait la largeur — et pas la
 * même selon la longueur des étiquettes, ce qui est précisément ce qui rendait
 * les deux cercles inégaux. En bas, les deux anneaux sont contraints par la
 * même hauteur restante, et le cercle est centré horizontalement dans sa
 * carte.
 *
 * @param {(context: object) => string} etiquetteInfobulle ce que dit l'infobulle
 */
export const optionsDAnneau = (etiquetteInfobulle) => ({
    locale: LANGUE,
    responsive: true,
    maintainAspectRatio: false,
    cutout: '65%',
    layout: { padding: 8 },
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                color: jeton('text-muted'),
                font: { family: "'Space Grotesk', sans-serif", size: 11, weight: 'bold' },
                padding: 12,
                boxHeight: 8,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            titleFont: { family: "'Space Grotesk', sans-serif", size: 13, weight: 'bold' },
            bodyColor: jeton('text-muted'),
            bodyFont: { family: "'Inter', sans-serif", size: 12 },
            padding: 12,
            cornerRadius: 12,
            boxPadding: 6,
            borderColor: jetonTransparent('shadow-cast', 0.05),
            borderWidth: 1,
            callbacks: { label: etiquetteInfobulle },
        },
    },
})
