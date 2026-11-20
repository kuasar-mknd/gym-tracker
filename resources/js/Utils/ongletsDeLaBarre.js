/**
 * Les quatre onglets de la barre du bas, et la table qui dit lequel s'allume
 * sur chaque page (#2434).
 *
 * Les pages secondaires déclarent leur retour (`back-route`) : la cible de ce
 * retour et la page qui l'a déclaré appartiennent au même onglet, et c'est ici
 * que cela se décide, une fois. Un onglet s'allume sur le préfixe de la route
 * (`goals.edit` → `goals`), pas sur son nom entier.
 *
 * Les notifications n'ont pas d'onglet : on y va par la cloche, et son retour
 * mène à l'Accueil sans que l'Accueil s'allume pour autant.
 */

/**
 * @typedef {object} Onglet
 * @property {string} cle
 * @property {string} nom
 * @property {string} icone
 * @property {string} route La page où mène l'onglet.
 * @property {string[]} familles Les préfixes de route qui lui appartiennent.
 */

/** @type {Onglet[]} */
export const ONGLETS = [
    {
        cle: 'accueil',
        nom: 'Accueil',
        icone: 'grid_view',
        route: 'dashboard',
        familles: ['dashboard'],
    },
    {
        cle: 'seances',
        nom: 'Séances',
        icone: 'calendar_month',
        route: 'workouts.index',
        familles: ['workouts', 'templates', 'calendar'],
    },
    {
        cle: 'progres',
        nom: 'Progrès',
        icone: 'bar_chart_4_bars',
        route: 'stats.index',
        familles: ['stats', 'corps', 'goals', 'achievements', 'body-measurements', 'body-parts', 'exercises'],
    },
    {
        cle: 'profil',
        nom: 'Profil',
        icone: 'person',
        route: 'profile.index',
        familles: ['profile', 'habits', 'daily-journals', 'supplements', 'tools', 'plates', 'shortcuts'],
    },
]

/**
 * L'onglet que cette route allume, ou null : une page sans onglet, ou une
 * adresse que Ziggy ne sait pas nommer.
 *
 * @param {string | null | undefined} nomDeRoute
 * @returns {Onglet | null}
 */
export const ongletDeLaRoute = (nomDeRoute) => {
    if (typeof nomDeRoute !== 'string' || nomDeRoute === '') {
        return null
    }

    const famille = nomDeRoute.split('.')[0]

    return ONGLETS.find((onglet) => onglet.familles.includes(famille)) ?? null
}
