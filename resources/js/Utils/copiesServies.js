import { PAGES_GARDEES, cleDeLaCopie, pageGardee } from '@/Utils/pagesGardees'

/**
 * La page qu'une copie hors ligne devient quand l'application ouverte la sert
 * (#2048, partie E, décisions 8 et 9 du propriétaire du 2026-10-12).
 *
 * Le magasin chiffré (`copiesHorsLigne.js`) rend l'objet de page d'Inertia tel
 * que le réseau l'a rendu. `visitesHorsLigne.js` le pousse dans l'application
 * par `router.push`, une visite côté client, qui recopie la page courante
 * (`...current`) avant d'y verser ce qu'on lui passe : ce qu'on ne nomme pas
 * survivrait de la page qu'on quitte, ses props différées, ses messages, son
 * état mémorisé. Chaque champ de la page servie est donc écrit ici, et tout
 * autre champ de la page courante remis à `undefined` (`pageServie`). Ce module
 * ne fait que des données, sans Inertia ni magasin : la page servie se vérifie
 * seule.
 *
 * **La marque d'une copie** est `props.horsLigne`, `{ copieDu, raison,
 * datesDesProps }` : l'instant de la dernière réponse complète que la copie
 * garde, pourquoi elle est montrée, `reseau` (aucune réponse) ou `lent` (pas de
 * réponse au bout de cinq secondes), et l'instant où chaque prop a été reçue
 * ou versée (une réponse partielle, l'instantané de la séance), que le bandeau
 * et la séance lisent (`dateDeLaCopie`, `dateDeLaProp`). Une réponse complète
 * du serveur l'efface d'elle-même, ses props remplaçant celles de la copie ;
 * une réponse partielle la garde, Inertia fusionnant les props. Aucune prop
 * partagée du serveur ne la porte : un `Inertia::always(null)` l'effacerait
 * sur une réponse partielle, alors que le reste de la page serait encore
 * celui de la copie.
 */

/** La prop qui marque une page servie depuis une copie. */
export const MARQUE_D_UNE_COPIE = 'horsLigne'

/** Une copie servie parce que la visite n'a reçu aucune réponse. */
export const RAISON_SANS_RESEAU = 'reseau'

/** Une copie servie parce que la visite n'avait pas de réponse au bout du délai. */
export const RAISON_RESEAU_LENT = 'lent'

/**
 * Les champs qu'une page servie écrit elle-même. Tout autre champ de la page
 * courante prend `undefined` : rien de la page quittée ne passe dans la copie.
 */
export const CHAMPS_DE_LA_PAGE_SERVIE = Object.freeze([
    'component',
    'url',
    'version',
    'props',
    'flash',
    'deferredProps',
    'initialDeferredProps',
    'rescuedProps',
    'mergeProps',
    'prependProps',
    'deepMergeProps',
    'matchPropsOn',
    'onceProps',
    'scrollProps',
    'sharedProps',
    'rememberedState',
    'clearHistory',
    'encryptHistory',
])

/**
 * Si la page affichée est une copie hors ligne.
 *
 * @param {{ props?: Record<string, unknown> } | null | undefined} page
 * @returns {boolean}
 */
export const estUneCopie = (page) => {
    const marque = page?.props?.[MARQUE_D_UNE_COPIE]

    return marque !== undefined && marque !== null
}

/** @param {unknown} instant */
const estUnInstant = (instant) => typeof instant === 'number' && Number.isFinite(instant)

/**
 * L'instant qu'une page servie montre : le plus récent de la dernière réponse
 * complète et des props versées depuis. La séance en cours se verse dans sa
 * copie à chaque instantané, bien après la réponse qui l'a ouverte : datée de
 * cette réponse, elle aurait paru plus ancienne que ce qu'elle montre.
 *
 * @param {{ copieDu?: unknown, datesDesProps?: Record<string, unknown> } | null | undefined} marque
 * @returns {number | null}
 */
export const dateDeLaCopie = (marque) => {
    const instants = [marque?.copieDu, ...Object.values(marque?.datesDesProps ?? {})].filter(estUnInstant)

    return instants.length === 0 ? null : Math.max(...instants)
}

/**
 * L'instant où une prop de la page servie a été reçue ou versée, sinon celui
 * de la dernière réponse complète.
 *
 * @param {{ copieDu?: unknown, datesDesProps?: Record<string, unknown> } | null | undefined} marque
 * @param {string} nom
 * @returns {number | null}
 */
export const dateDeLaProp = (marque, nom) => {
    const date = marque?.datesDesProps?.[nom]

    if (estUnInstant(date)) {
        return date
    }

    return estUnInstant(marque?.copieDu) ? marque.copieDu : null
}

/**
 * Si une adresse, sans origine, peut avoir une copie : sa clé est celle d'une
 * page de la liste blanche, quel qu'en soit le composant. Ce qu'on vérifie
 * avant de rien demander au magasin.
 *
 * @param {string | null | undefined} adresse
 * @returns {boolean}
 */
export const adressePeutAvoirUneCopie = (adresse) => {
    const cle = cleDeLaCopie(adresse)

    return cle !== null && PAGES_GARDEES.some((page) => pageGardee(cle, page.composant) !== null)
}

/**
 * Si une prop est absente des props d'une page : un nom pointé (`a.b`) se lit
 * en profondeur, comme `<Deferred>` le lit.
 *
 * @param {Record<string, unknown>} props
 * @param {string} nom
 * @returns {boolean}
 */
export const propAbsente = (props, nom) => {
    let valeur = props

    for (const morceau of String(nom).split('.')) {
        if (valeur === null || typeof valeur !== 'object' || !Object.hasOwn(valeur, morceau)) {
            return true
        }

        valeur = valeur[morceau]
    }

    return valeur === undefined
}

/**
 * Les props différées qu'une page annonce, d'après ses annonces d'origine
 * (`initialDeferredProps`, que la page garde après le chargement), sinon
 * celles qu'elle porte encore.
 *
 * @param {{ deferredProps?: Record<string, string[]>, initialDeferredProps?: Record<string, string[]> } | null | undefined} page
 * @returns {string[]}
 */
export const propsDiffereesAnnoncees = (page) => [
    ...new Set(
        Object.values(page?.initialDeferredProps ?? page?.deferredProps ?? {})
            .flat()
            .filter((nom) => typeof nom === 'string'),
    ),
]

/**
 * Les props différées qu'une page annonce et que ses props n'ont pas : ce
 * qu'une copie montre « Indisponible hors ligne » (`rescuedProps`).
 *
 * @param {{ props?: Record<string, unknown>, deferredProps?: Record<string, string[]>, initialDeferredProps?: Record<string, string[]> }} page
 * @returns {string[]}
 */
export const propsDiffereesAbsentes = (page) =>
    propsDiffereesAnnoncees(page).filter((nom) => propAbsente(page?.props ?? {}, nom))

/**
 * `auth` tel qu'une copie servie le montre : le badge à célébrer retiré
 * (`latest_achievement`), qu'une page qui ne vient pas du serveur ne doit pas
 * faire célébrer une seconde fois.
 *
 * @param {{ user?: Record<string, unknown> | null } | null | undefined} auth
 */
const authSansBadge = (auth) =>
    auth?.user !== null && typeof auth?.user === 'object'
        ? { ...auth, user: { ...auth.user, latest_achievement: null } }
        : auth

/**
 * La page qu'on pousse pour servir une copie : chaque champ de la page
 * d'Inertia écrit, et tout autre champ de la page courante remis à
 * `undefined`.
 *
 * - `component`, `url` : ceux de la copie ; `version` : celle de la page
 *   courante, qui est celle du code qui tourne : une copie écrite sous une
 *   autre version ne fait ni redemander l'entrée d'historique au bouton
 *   Retour, ni recharger tout le document à la première requête ;
 * - `props` : celles de la copie, avec le `auth` le plus récent du compte,
 *   sans badge à célébrer, la marque `horsLigne` (datée par la copie, prop par
 *   prop) et aucune erreur ;
 * - `flash` vide ; ni props différées à charger (`deferredProps`,
 *   `initialDeferredProps` : `page.set` les demanderait, et le bouton Retour
 *   les redemanderait), mais `rescuedProps` qui nomme celles que la copie n'a
 *   pas, que `<Deferred>` montre par son créneau `#rescue` ;
 * - rien à fusionner, aucune prop d'une seule fois, aucun défilement infini,
 *   aucun état mémorisé, l'historique gardé ;
 * - `encryptHistory: true`, écrit en dur et jamais pris à la page courante :
 *   une entrée en clair se relirait au bouton Retour, même sans clé.
 *
 * @param {{
 *   copie: { page: Record<string, any>, copieDu: number, datesDesProps?: Record<string, number> },
 *   pageCourante: Record<string, unknown> | null,
 *   auth: unknown,
 *   raison: string,
 * }} options `auth` : le plus récent du compte de la copie
 * @returns {Record<string, unknown>}
 */
export const pageServie = ({ copie, pageCourante, auth, raison }) => {
    const page = copie.page
    const champsEcrits = new Set(CHAMPS_DE_LA_PAGE_SERVIE)
    const effaces = Object.fromEntries(
        Object.keys(pageCourante ?? {})
            .filter((champ) => !champsEcrits.has(champ))
            .map((champ) => [champ, undefined]),
    )

    return {
        ...effaces,
        component: page.component,
        url: page.url,
        version: pageCourante?.version ?? page.version ?? null,
        props: {
            ...page.props,
            auth: authSansBadge(auth),
            [MARQUE_D_UNE_COPIE]: {
                copieDu: copie.copieDu,
                raison,
                datesDesProps: Object.fromEntries(
                    Object.entries(copie.datesDesProps ?? {}).filter(([, date]) => estUnInstant(date)),
                ),
            },
            errors: {},
        },
        flash: {},
        deferredProps: undefined,
        initialDeferredProps: undefined,
        rescuedProps: propsDiffereesAbsentes(page),
        mergeProps: [],
        prependProps: [],
        deepMergeProps: [],
        matchPropsOn: [],
        onceProps: {},
        scrollProps: undefined,
        sharedProps: Array.isArray(page.sharedProps) ? [...page.sharedProps] : undefined,
        rememberedState: {},
        clearHistory: false,
        encryptHistory: true,
    }
}
