/**
 * Les pages gardées pour la salle, sans réseau (#2048, décisions du
 * propriétaire du 2026-10-12).
 *
 * La page garde des copies chiffrées des objets de page d'Inertia d'une courte
 * liste de pages utiles en salle, pour un seul compte (`copiesHorsLigne.js`).
 * Ce module ne dit que lesquelles, sous quelle clé, et dans quelles limites :
 * de pures données et des fonctions sans état, sans import, que la page lit,
 * et que le worker et `vite.config.js` lisent sans charger ni Vue ni
 * Inertia. Le worker ne lit jamais rien d'un compte : il n'en lit que les
 * chemins (`cleGardee`), pour savoir quelle navigation attend le réseau huit
 * secondes au plus avant la coquille.
 *
 * Le noyau de salle, sans `Exercises/Show` au départ (décision 2) : l'accueil,
 * le `start_url` ; la séance en cours et les trois dernières séances closes
 * visitées ; la liste des séances, page 1 sans filtre ; la bibliothèque ; les
 * menus « Plus » (le profil et les outils) ; le minuteur d'intervalles, les
 * disques, le 1RM et l'échauffement. Jamais une page de santé, de journal, de
 * notifications, d'objectifs, de compléments, le profil à modifier, un
 * formulaire de création ou d'édition, ni une page d'invité :
 * `LesPagesPriveesNeSontJamaisGardeesTest` (tests/Feature/Conventions) le
 * tient, et `PagesGardeesTest` compare chaque chemin à la route qu'il nomme.
 * Ce qu'une page gardée montre de ces pages-là (le dernier poids et les
 * objectifs de l'accueil) ne va pas non plus dans sa copie
 * (`PROPS_JAMAIS_GARDEES`).
 *
 * Chaque entrée tient sur une ligne, sous la forme
 * `{ composant: '…', route: '…', chemin: '…' }` : les tests PHP la lisent
 * ainsi (`Tests\Support\PagesGardees`), et refusent toute autre forme.
 */

/**
 * La version du contrat des copies : la forme des props des pages gardées,
 * clés et types. Une copie la porte (données authentifiées du chiffrement, et
 * l'état du magasin), et une copie d'une autre version ne se lit plus.
 *
 * Elle ne suit pas la construction : un déploiement qui ne change pas la forme
 * des props ne jette donc pas les copies. `ContratDesPagesGardeesTest` relève
 * la forme des props de chaque page gardée et la compare à celle que fige
 * `tests/Feature/HorsLigne/contrat-des-pages-gardees.json` pour cette version :
 * une forme qui change sans que la version monte fait échouer la suite.
 */
export const VERSION_DU_CONTRAT = 1

/**
 * Le volume que les copies d'un compte occupent au plus, chiffrées : 1,5 Mo.
 * Les pages du noyau en demandent de 150 à 300 Ko. Au-delà, la plus ancienne
 * sort la première, jamais la séance en cours.
 */
export const PLAFOND_DES_COPIES_EN_OCTETS = 1_500_000

/** Le composant de la séance, gardée en cours et pour quelques séances closes. */
export const COMPOSANT_DE_LA_SEANCE = 'Workouts/Show'

/** Les séances closes visitées gardées en plus de la séance en cours : les plus récentes. */
export const SEANCES_CLOSES_GARDEES = 3

/** L'âge au-delà duquel une copie s'efface, par hygiène : sept jours. */
export const AGE_MAXIMAL_D_UNE_COPIE_MS = 7 * 24 * 60 * 60 * 1000

/**
 * @typedef {{ composant: string, route: string, chemin: string }} PageGardee
 *   `composant` : le composant d'Inertia que la page rend ; `route` : le nom
 *   de sa route ; `chemin` : son adresse sans origine, un segment entre
 *   accolades pour un identifiant (`/workouts/{workout}`).
 */

/**
 * Gèle chaque entrée et la liste.
 *
 * @param {PageGardee[]} pages
 * @returns {readonly PageGardee[]}
 */
const geler = (pages) => Object.freeze(pages.map((page) => Object.freeze(page)))

/** La liste blanche. */
export const PAGES_GARDEES = geler([
    { composant: 'Dashboard', route: 'dashboard', chemin: '/dashboard' },
    { composant: 'Workouts/Show', route: 'workouts.show', chemin: '/workouts/{workout}' },
    { composant: 'Workouts/Index', route: 'workouts.index', chemin: '/workouts' },
    { composant: 'Exercises/Index', route: 'exercises.index', chemin: '/exercises' },
    { composant: 'Profile/Index', route: 'profile.index', chemin: '/profile' },
    { composant: 'Tools/Index', route: 'tools.index', chemin: '/tools' },
    { composant: 'Tools/IntervalTimer', route: 'tools.interval-timer.index', chemin: '/tools/interval-timer' },
    { composant: 'Tools/PlateCalculator', route: 'plates.index', chemin: '/plates' },
    { composant: 'Tools/OneRepMax', route: 'tools.1rm', chemin: '/tools/1rm' },
    { composant: 'Tools/WarmupCalculator', route: 'tools.warmup', chemin: '/tools/warmup' },
])

/**
 * Les props qu'une page gardée porte et qu'aucune copie ne garde : la liste
 * « Jamais gardées » de l'issue écarte les mesures et les objectifs, que
 * l'accueil montre aussi (le dernier poids, tiré des mesures, et les objectifs
 * en cours, objectifs de mesure compris). Le magasin les retire avant de
 * chiffrer (`copiesHorsLigne.js`), à l'écriture d'une réponse complète comme
 * d'une réponse partielle, et ne les reprend jamais d'une copie précédente ;
 * une copie servie (partie E) les montrera indisponibles hors ligne.
 *
 * Le contrat des pages gardées (`ContratDesPagesGardeesTest`) les relève sans
 * elles, et `LesPagesPriveesNeSontJamaisGardeesTest` y refuse une prop de
 * mesure, de santé ou d'objectif qui n'est pas ici. Une entrée par ligne, sous
 * la forme `Composant: Object.freeze(['prop', …]),`, que
 * `Tests\Support\PagesGardees` lit et refuse sous toute autre.
 */
export const PROPS_JAMAIS_GARDEES = Object.freeze({
    Dashboard: Object.freeze(['latestWeight', 'activeGoals']),
})

/**
 * Les champs des props partagées qui ne valent que pour la page qui suit une
 * écriture : le message de réussite ou d'échec qu'une redirection laisse en
 * session (`flash.success`, `flash.error`, `HandleInertiaRequests::share()`).
 * Une copie s'écrit justement sur cette page-là (une bibliothèque où l'on
 * vient de créer un exercice) : servie plus tard (partie E), elle remontrerait
 * un message d'un instant. Le magasin les ramène donc à nul avant de chiffrer
 * (`propsGardables`), à l'écriture d'une réponse complète comme d'une réponse
 * partielle, et ne garde pas non plus le `flash` de la page (`Inertia::flash`).
 *
 * Le contrat des pages gardées (`ContratDesPagesGardeesTest`) les relève
 * ramenés à nul, et exige qu'ils soient bien dans la réponse. Une entrée par
 * ligne, sous la forme `prop: Object.freeze(['champ', …]),`, que
 * `Tests\Support\PagesGardees` lit et refuse sous toute autre.
 */
export const CHAMPS_D_UN_INSTANT = Object.freeze({
    flash: Object.freeze(['success', 'error']),
})

/**
 * Une prop telle qu'une copie la garde : ses champs d'un instant ramenés à
 * nul quand elle en a (`CHAMPS_D_UN_INSTANT`), telle quelle sinon. Un champ
 * absent n'est pas ajouté.
 *
 * @param {string} nom
 * @param {unknown} valeur
 * @returns {unknown}
 */
const sansSesChampsDUnInstant = (nom, valeur) => {
    if (
        !Object.hasOwn(CHAMPS_D_UN_INSTANT, nom) ||
        valeur === null ||
        typeof valeur !== 'object' ||
        Array.isArray(valeur)
    ) {
        return valeur
    }

    return {
        ...valeur,
        ...Object.fromEntries(
            CHAMPS_D_UN_INSTANT[nom].filter((champ) => Object.hasOwn(valeur, champ)).map((champ) => [champ, null]),
        ),
    }
}

/**
 * Si une prop d'un composant ne va jamais dans une copie.
 *
 * @param {unknown} composant
 * @param {unknown} nom
 * @returns {boolean}
 */
export const propJamaisGardee = (composant, nom) =>
    typeof composant === 'string' &&
    Object.hasOwn(PROPS_JAMAIS_GARDEES, composant) &&
    PROPS_JAMAIS_GARDEES[composant].includes(nom)

/**
 * Les props d'une page telles qu'une copie les garde, sans celles qu'aucune
 * copie ne garde (`PROPS_JAMAIS_GARDEES`), les champs d'un instant ramenés à
 * nul (`CHAMPS_D_UN_INSTANT`) : un nouvel objet, les autres props telles
 * quelles.
 *
 * @param {unknown} composant
 * @param {Record<string, unknown> | null | undefined} props
 * @returns {Record<string, unknown>}
 */
export const propsGardables = (composant, props) =>
    Object.fromEntries(
        Object.entries(props ?? {})
            .filter(([nom]) => !propJamaisGardee(composant, nom))
            .map(([nom, valeur]) => [nom, sansSesChampsDUnInstant(nom, valeur)]),
    )

/**
 * Le motif qu'un chemin de la liste reconnaît : un identifiant de modèle, un
 * entier positif, à la place de chaque segment entre accolades.
 *
 * @param {string} chemin
 * @returns {RegExp}
 */
const motifDuChemin = (chemin) =>
    new RegExp(
        `^${chemin
            .split('/')
            .map((segment) =>
                /^\{\w+\}$/.test(segment) ? '[1-9][0-9]*' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
            )
            .join('/')}$`,
    )

/**
 * Compare deux paires (nom, valeur) d'une chaîne de requête, nom d'abord.
 *
 * @param {[string, string]} premiere
 * @param {[string, string]} seconde
 */
const comparerLesParametres = ([nomA, valeurA], [nomB, valeurB]) => {
    if (nomA !== nomB) {
        return nomA < nomB ? -1 : 1
    }

    if (valeurA === valeurB) {
        return 0
    }

    return valeurA < valeurB ? -1 : 1
}

/**
 * Le chemin et la chaîne de requête d'une adresse, ou `null`.
 *
 * Une adresse relative se découpe telle quelle, sans être résolue contre une
 * origine : un chemin qui n'est pas déjà sous sa forme normale (`..`, une
 * barre oblique inverse) ne reconnaîtra aucune page gardée.
 *
 * @param {string} adresse
 * @param {string | undefined} origine
 * @returns {{ chemin: string, requete: string } | null}
 */
const decouper = (adresse, origine) => {
    if (adresse.startsWith('/') && !adresse.startsWith('//')) {
        const [sansFragment] = adresse.split('#')
        const separateur = sansFragment.indexOf('?')

        return separateur === -1
            ? { chemin: sansFragment, requete: '' }
            : { chemin: sansFragment.slice(0, separateur), requete: sansFragment.slice(separateur + 1) }
    }

    if (origine === undefined) {
        return null
    }

    try {
        const url = new URL(adresse)

        return url.origin === new URL(origine).origin ? { chemin: url.pathname, requete: url.search.slice(1) } : null
    } catch {
        return null
    }
}

/**
 * La clé d'une copie : le chemin normalisé de l'adresse d'une page.
 *
 * Les paramètres de la requête sont triés, `page=1` retiré, `/` réécrit en
 * `/dashboard`, la barre finale et le fragment retirés. Une adresse s'écrit
 * sans origine (`page.url` d'Inertia) ; une adresse absolue n'est admise que de
 * l'origine donnée. Rien d'autre ne donne de clé : `null`.
 *
 * @param {unknown} adresse
 * @param {string} [origine] l'origine de l'application, pour une adresse absolue
 * @returns {string | null}
 */
export const cleDeLaCopie = (adresse, origine) => {
    const morceaux = typeof adresse === 'string' ? decouper(adresse, origine) : null

    if (morceaux === null) {
        return null
    }

    const chemin = morceaux.chemin.replace(/\/+$/, '')
    const parametres = [...new URLSearchParams(morceaux.requete)]
        .filter(([nom, valeur]) => !(nom === 'page' && valeur === '1'))
        .sort(comparerLesParametres)
    const requete = new URLSearchParams(parametres).toString()
    const normalise = chemin === '' ? '/dashboard' : chemin

    return requete === '' ? normalise : `${normalise}?${requete}`
}

/**
 * La page gardée que nomment une clé et un composant, ou `null`.
 *
 * Le composant doit être celui que la liste attend à ce chemin : une adresse
 * gardée qui aurait rendu une autre page (une redirection vers la connexion,
 * une page d'erreur) n'est pas gardée. Une clé qui porte encore des
 * paramètres (un filtre, une autre page de la liste des séances) non plus :
 * seule la vue par défaut de chaque page est gardée.
 *
 * @param {string | null} cle une clé rendue par `cleDeLaCopie`
 * @param {unknown} composant
 * @returns {PageGardee | null}
 */
export const pageGardee = (cle, composant) => {
    if (typeof cle !== 'string' || cle.includes('?')) {
        return null
    }

    return PAGES_GARDEES.find((page) => page.composant === composant && motifDuChemin(page.chemin).test(cle)) ?? null
}

/**
 * Si une clé est celle d'une page de la liste, quel que soit le composant que
 * le serveur rendra : ce que le worker lit d'une navigation, avant toute
 * réponse (`resources/js/sw/navigationsHorsLigne.js`). Comme pour
 * `pageGardee`, une clé qui porte encore des paramètres n'en est pas une : le
 * motif de chaque chemin s'arrête à sa fin.
 *
 * @param {string | null} cle une clé rendue par `cleDeLaCopie`
 * @returns {boolean}
 */
export const cleGardee = (cle) =>
    typeof cle === 'string' && PAGES_GARDEES.some((page) => motifDuChemin(page.chemin).test(cle))

/**
 * La clé de la séance en cours d'après le compte qu'une page déclare
 * (`auth.user.active_workout`), ou `null` sans séance ouverte : celle que le
 * plafond ne fait jamais sortir.
 *
 * @param {{ props?: { auth?: { user?: { active_workout?: { id?: unknown } | null } | null } } } | null | undefined} page
 * @returns {string | null}
 */
export const cleDeLaSeanceEnCours = (page) => {
    const identifiant = page?.props?.auth?.user?.active_workout?.id

    return Number.isInteger(identifiant) && identifiant > 0 ? `/workouts/${identifiant}` : null
}
