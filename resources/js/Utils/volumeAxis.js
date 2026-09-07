import { nombre } from '@/Utils/nombre'

/**
 * Abrège une graduation de volume au-delà du millier.
 *
 * Une décimale, pas zéro : arrondir au millier écrase toute échelle plus fine.
 * Avec le pas de 500 kg que Chart.js choisit pour les petits volumes, la
 * graduation de 1500 s'étiquetait « 2k » et l'axe se lisait « 1k, 2k, 2k, 3k,
 * 3k » — des étiquettes en double, fausses de 500 kg chacune. MonthlyVolumeChart
 * a livré cette version-là pendant que VolumeTrendChart, qui tenait une copie de
 * la même expression, ne l'avait pas. Deux copies d'une règle, c'est ainsi que
 * l'une des deux se trompe ; celle-ci est la seule.
 *
 * En dessous du millier, la valeur revient telle quelle — le nombre que
 * Chart.js a donné, pas une chaîne — pour que l'axe garde sa propre mise en
 * forme au petit bout.
 *
 * @param {number} value La valeur de la graduation, en kilos.
 * @returns {string|number} « 1,5k » à partir du millier, la valeur elle-même en dessous.
 */
export function formatVolumeTick(value) {
    if (value >= 1000) {
        return `${nombre(value / 1000)}k`
    }

    return value
}
