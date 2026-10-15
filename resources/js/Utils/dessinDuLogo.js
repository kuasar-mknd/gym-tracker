/**
 * Le dessin du logo de l'application : le G hexagonal et ses trois barres.
 *
 * C'est le dessin de public/logo.svg, repris ici pour que l'application le
 * trace sans requête : l'en-tête reste entier hors ligne, où public/ n'est pas
 * précaché, et la page « hors ligne » ne charge rien d'autre que sa feuille de
 * style. Ses couleurs sont les utilitaires `fill-logo-lettre` et
 * `fill-logo-barres`, tirés des jetons de la charte. `IconesDeLApplicationTest`
 * exige que ces tracés et ce cadre soient ceux de public/logo.svg, et que
 * public/icone.svg et public/badge.svg portent les mêmes tracés.
 */

/** Le cadre de public/logo.svg : le dessin, avec une marge d'une vingtaine d'unités. */
export const CADRE_DU_LOGO = '247.5 243 760 760'

/** Les deux tracés du G : l'hexagone ouvert, puis son bras intérieur. */
export const TRACES_DE_LA_LETTRE = [
    'M358 405q-54 30-52 96l-1 262q5 50 49 77l223 129q42 22 79-1l51-30q17-9-4-24l-262-157q-14-9-16-32v-196q0-31 22-41l163-92q17-9 36 0l135 77q17 9 35-2l87-48q9-6-3-14l-220-128q-50-27-99-2',
    'M537 616q-8 5-9 19v68q0 9 20-1l174-94q4-2 9-2h109q13-1 14 9v230q0 8 15 1l48-28q31-17 33-49v-198q2-42-34-46l-195-3q-6 0-15 4',
]

/** Les trois barres, de la plus haute à la plus courte. */
export const TRACES_DES_BARRES = [
    'M745 662q-9 5-9 17v225q0 19 17 8l72-42q8-5 7-16v-215q0-19-24-9',
    'M633 719q-8 5-8 17v99q0 9 8 14l73 44q6 3 7-9v-197q0-9-14-3',
    'M544 766c-9 5-9 24-3 28l55 33q7 4 8-9v-74q0-9-14-3',
]

/**
 * Les deux dispositions de la marque : dans la barre de navigation, et
 * au-dessus de la carte d'une page invitée et de la page « hors ligne ».
 *
 * Le nom ne se montre dans aucune, à la demande du propriétaire (2026-10-14
 * au-dessus des pages, 2026-10-15 dans la barre) : le logo suffit, et le nom
 * reste lu, il nomme le lien vers l'accueil et la page « hors ligne ».
 *
 * La barre de bureau, ouverte dès `sm`, n'avait pas la place du logo et du
 * nom à la fois (#2057) : avec un
 * compte « Test User », ils lui demandaient 1 103 px de large jusqu'au bord du
 * menu du compte, et l'ancien en-tête, le nom seul, 1 051 px. Le logo seul en
 * demande 898, et 947 avec un nom de compte long. En deçà, la barre déborde et
 * rogne le menu du compte, chevron compris. Un ascenseur classique (Windows,
 * Linux, Dusk) prend 15 px de plus à la fenêtre ; Playwright le cache par
 * défaut (`--hide-scrollbars`). Mesures dans Chromium.
 */
export const DISPOSITIONS_DE_LA_MARQUE = {
    barre: {
        logo: 'size-10 rounded p-1 shadow-sm',
        nom: 'sr-only',
        sens: 'flex-row gap-3',
    },
    page: { logo: 'size-20 rounded-lg p-2 shadow-lg', nom: 'sr-only', sens: 'flex-col gap-3' },
}

/**
 * La tuile du logo dans l'application, celle de son icône (public/icone.svg).
 *
 * Sans elle, l'orange des barres ne rendait que 2,86:1 sur le fond que teinte
 * LiquidBackground au-dessus de la carte de connexion, sous les 3:1 d'un
 * graphique (WCAG 1.4.11) ; sur la tuile, il en rend 3,21 quel que soit le
 * fond. Son arrondi et sa marge intérieure, un cinquième et un dixième du
 * côté, sont à peu de chose près ceux de l'icône (100 et 48 unités sur 512).
 */
export const TUILE_DU_LOGO = 'shrink-0 bg-logo-fond'

const traces = (liste) => liste.map((trace) => `<path d="${trace}"/>`).join('')

/**
 * Le logo en SVG, pour une page écrite hors de Vue. Décoratif : la page écrit
 * le nom de l'application à côté, qui nomme ce qui l'entoure.
 *
 * @param {string} classes Les classes du SVG, à commencer par sa taille.
 * @returns {string}
 */
export const logoEnSvg = (classes) =>
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${CADRE_DU_LOGO}" class="${classes}" aria-hidden="true" focusable="false">` +
    `<g class="fill-logo-lettre">${traces(TRACES_DE_LA_LETTRE)}</g>` +
    `<g class="fill-logo-barres">${traces(TRACES_DES_BARRES)}</g>` +
    '</svg>'
