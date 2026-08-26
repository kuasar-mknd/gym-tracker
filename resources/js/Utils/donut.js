/**
 * Ce que les anneaux du tableau de bord ont en commun.
 *
 * `DurationDistributionChart` et `TimeOfDayChart` sont empilés l'un sous
 * l'autre et portaient chacun leur copie des options. Elles avaient dérivé :
 * polices différentes, `layout.padding` sur un seul des deux, bordures
 * différentes. D'où deux cercles de tailles visiblement différentes — mesurés
 * à 172 px et 151 px de diamètre sur un écran de 375 px (#1316).
 *
 * Une seule source, donc, et la dérive n'a plus où se loger.
 */

import { jeton, jetonTransparent } from '@/Utils/couleurs'

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

/**
 * Rapporte le centre RÉEL de l'anneau, après chaque tracé.
 *
 * L'icône centrale de `TimeOfDayChart` était posée en `absolute inset-0` avec
 * un `-ml-[120px]` codé en dur pour compenser la légende de droite. 120 px
 * n'était juste sous aucune largeur d'écran : mesuré, l'icône tombait à 41 px
 * du vrai centre. Et la valeur aurait été à refaire à chaque étiquette plus
 * longue.
 *
 * Chart.js connaît ce centre — c'est `x`/`y` du premier arc. Le demander plutôt
 * que le deviner rend la position juste quelle que soit la légende, la largeur
 * ou la police.
 *
 * @param {(centre: {x: number, y: number}) => void} rapporte
 */
export const pluginCentreDAnneau = (rapporte) => ({
    id: 'centreDAnneau',
    afterDraw(chart) {
        const arc = chart.getDatasetMeta(0)?.data?.[0]

        if (arc) {
            rapporte({ x: arc.x, y: arc.y })
        }
    },
})
