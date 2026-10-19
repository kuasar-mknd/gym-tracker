import { authSansBadge, champsDeLaPageQuittee } from '@/Utils/copiesServies'
import { dateCourte } from '@/Utils/date'
import { lireLaListe, normaliserLesIdentifiants } from '@/Utils/listePartagee'
import { compte } from '@/Utils/nombre'
import { COMPOSANT_DE_LA_SEANCE, PAGES_GARDEES, cleDeLaSeanceEnCours } from '@/Utils/pagesGardees'
import { cleDeLaCreation, estUneCreationDeSeance, identifiantEnFile } from '@/Utils/seanceEtFile'

/**
 * Une séance démarrée sans réseau (#2049) : une création en file, que la page
 * montre comme la séance qu'elle sera.
 *
 * « Démarrer » sans réponse du serveur met en file `POST /api/v1/workouts`,
 * sous une clé d'idempotence tirée par l'appareil (`Idempotency-Key`), avec
 * l'heure du geste (`started_at`). La séance s'ouvre aussitôt : la page
 * `Workouts/Show`, construite ici depuis l'entrée de sa création
 * (`pageDeLaSeanceEnAttente`), sous l'identifiant `temp-q<queueId>` des
 * rangées superposées (`Utils/seanceEtFile.js`), à l'adresse
 * `/workouts/en-attente/{clé}`. Ses écritures la nomment par l'entrée de sa
 * création (`{ enAttenteDe }`), et la file les range derrière elle.
 *
 * Ce n'est pas une copie : elle ne vient d'aucune réponse du serveur, ne porte
 * pas la marque `horsLigne` (le retour du réseau rechargerait sinon la page,
 * que le serveur ne connaît pas encore, et le bandeau dirait « copie du »), et
 * ne s'écrit jamais dans le magasin des copies : son adresse n'est pas dans la
 * liste blanche. Une fois la création rejouée, la page passe à la séance
 * réelle (`composables/useSeanceEnAttente.js`) ; rechargée en ligne, ou
 * rouverte par l'historique, son adresse mène à la séance que la clé désigne
 * (`workouts.en-attente`, une redirection du serveur).
 *
 * Tout ici est pur : ni Vue, ni Inertia, ni la file elle-même, et aucun
 * `route()` à l'import. La coquille hors ligne s'en sert sans charger
 * `SyncService` (`Utils/demarrageHorsLigne.js`), qui lui prend la clé du
 * stockage de sa file.
 */

/** La clé du `localStorage` sous laquelle la file hors ligne garde ses écritures (`SyncService`). */
export const CLE_DE_LA_FILE = 'offline_sync_queue'

/** La clé du `localStorage` sous laquelle la file garde ce que le serveur a refusé. */
export const CLE_DES_REFUS = 'offline_sync_failed'

/** Le début de l'adresse d'une séance démarrée sans réseau (`workouts.en-attente`). */
export const CHEMIN_D_UNE_SEANCE_EN_ATTENTE = '/workouts/en-attente/'

/** Ce qu'une clé de séance peut être : celle que la route accepte. */
const MOTIF_DE_LA_CLE = /^[0-9A-Za-z-]{1,64}$/

/** Ce que dit la séance tant que sa création attend la file. */
export const SEANCE_EN_ATTENTE = 'Séance démarrée sans réseau : elle sera enregistrée au retour du réseau.'

/** Ce que dit la séance que le serveur a refusée, une autre étant déjà en cours. */
export const SEANCE_REFUSEE =
    "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours."

/** Ce que dit une séance que le serveur a refusée pour une autre raison. */
export const SEANCE_NON_ENREGISTREE = "La séance démarrée sans réseau n'a pas été enregistrée."

/**
 * Ce que dit une séance dont la création n'est plus en file sur cet appareil,
 * sans que la page sache ce qu'elle est devenue, le serveur ne répondant pas.
 */
export const SEANCE_A_RETROUVER =
    "Cette séance n'attend plus sur cet appareil : elle se retrouvera au retour du réseau."

/** La raison du refus d'une séance démarrée quand une autre est en cours (`UneSeanceEstDejaEnCours`). */
export const RAISON_SEANCE_DEJA_EN_COURS = 'seance-deja-en-cours'

/**
 * Combien d'écritures le refus de la création `queueId` emporte : celles que
 * la file a refusées faute de parent (`parentRefuse`), qui la nomment, ou
 * nomment une écriture qu'elle a emportée (la série d'un exercice ajouté à la
 * séance refusée).
 *
 * @param {Array<object>} refus les refus du compte (`SyncService.failedRequests()`)
 * @param {string} queueId
 * @returns {number}
 */
export const emporteesParLeRefus = (refus, queueId) => {
    const emportees = new Set([queueId])
    let trouvee = true

    while (trouvee) {
        trouvee = false

        for (const refusee of Array.isArray(refus) ? refus : []) {
            if (refusee?.parentRefuse === true && emportees.has(refusee.parent) && !emportees.has(refusee.id)) {
                emportees.add(refusee.id)
                trouvee = true
            }
        }
    }

    return emportees.size - 1
}

/**
 * Ce que dit, une fois, le refus d'une séance démarrée sans réseau : pourquoi,
 * et combien de ses écritures ne partiront pas avec elle.
 *
 * @param {string|null|undefined} raison celle que le serveur a donnée
 * @param {number} emportees `emporteesParLeRefus`
 * @returns {string}
 */
export const messageDuRefusDeLaSeance = (raison, emportees = 0) => {
    const refus = raison === RAISON_SEANCE_DEJA_EN_COURS ? SEANCE_REFUSEE : SEANCE_NON_ENREGISTREE

    if (!(emportees > 0)) {
        return refus
    }

    return `${refus} ${compte(emportees, 'modification', 'modifications')} de cette séance ne ${emportees > 1 ? 'partiront' : 'partira'} pas.`
}

/** Le chemin de la bibliothèque d'exercices, celle que la liste blanche garde. */
const CHEMIN_DE_LA_BIBLIOTHEQUE = PAGES_GARDEES.find((page) => page.composant === 'Exercises/Index')?.chemin ?? null

export { estUneCreationDeSeance }

/**
 * L'adresse de la séance démarrée sous cette clé, ou null pour une clé que la
 * route n'accepterait pas.
 *
 * @param {unknown} cle
 * @returns {string|null}
 */
export const adresseDUneSeanceEnAttente = (cle) =>
    typeof cle === 'string' && MOTIF_DE_LA_CLE.test(cle) ? `${CHEMIN_D_UNE_SEANCE_EN_ATTENTE}${cle}` : null

/**
 * La clé que nomme l'adresse d'une séance en attente, ou null pour toute autre
 * adresse : un chemin voisin, une clé de trop ou de travers.
 *
 * @param {unknown} adresse le chemin, avec ou sans requête ni fragment
 * @returns {string|null}
 */
export const cleDeLAdresseEnAttente = (adresse) => {
    if (typeof adresse !== 'string') {
        return null
    }

    const chemin = adresse.split(/[?#]/, 1)[0]

    if (!chemin.startsWith(CHEMIN_D_UNE_SEANCE_EN_ATTENTE)) {
        return null
    }

    const cle = chemin.slice(CHEMIN_D_UNE_SEANCE_EN_ATTENTE.length)

    return MOTIF_DE_LA_CLE.test(cle) ? cle : null
}

/** Une entrée qui crée une séance et que l'écran n'a pas retirée. */
const estUneSeanceEnFile = (entree) => estUneCreationDeSeance(entree) && entree?.aAnnuler === undefined

/** Si une valeur de charge nomme la création `queueId` encore en file. */
const nommeLaCreation = (valeur, queueId) =>
    valeur !== null && typeof valeur === 'object' && valeur.enAttenteDe === queueId

/**
 * La séance que la page montre d'une création en file : chaque champ de
 * `workout` que la page de séance reçoit du serveur (le contrat de
 * `Workouts/Show`), sous l'identifiant `temp-q<queueId>`, sans ligne : celles
 * que la file attend se superposent ensuite (`superposerLaFile`). Le nom est
 * la date du début, comme le serveur le donne ; il vient de l'appareil
 * jusqu'au rejeu.
 *
 * @param {object} entree l'entrée de la création
 * @returns {object}
 */
export const seanceDeLEntree = (entree) => {
    const debut =
        typeof entree?.data?.started_at === 'string' && entree.data.started_at !== ''
            ? entree.data.started_at
            : (entree?.timestamp ?? null)
    const compte = Number(entree?.compte)
    const nom = typeof entree?.data?.name === 'string' && entree.data.name !== '' ? entree.data.name : null

    return {
        id: identifiantEnFile(entree?.id),
        user_id: Number.isInteger(compte) ? compte : (entree?.compte ?? null),
        name: nom ?? (dateCourte(new Date(debut)) || 'Séance'),
        started_at: debut,
        ended_at: null,
        notes: null,
        idempotency_key: cleDeLaCreation(entree),
        created_at: debut,
        updated_at: debut,
        workout_volume: 0,
        workout_lines: [],
    }
}

/**
 * La création de séance en file sous cette clé, ou undefined.
 *
 * @param {Array<object>} entrees
 * @param {string|null} cle
 * @returns {object|undefined}
 */
export const creationDeLaCle = (entrees, cle) =>
    cle === null
        ? undefined
        : (Array.isArray(entrees) ? entrees : []).find(
              (entree) => estUneSeanceEnFile(entree) && cleDeLaCreation(entree) === cle,
          )

/**
 * La séance en cours sur l'appareil : celle du serveur (`auth.user.active_workout`),
 * ou celle qu'une création en file fait naître.
 *
 * Une création de séance en file est la séance en cours : la personne l'a
 * démarrée, et la page l'ouvre (le bandeau aussi, `ouvrir` côté client, par
 * son entrée `enAttenteDe`). Une séance du serveur qui porte la clé de cette
 * création est la même séance, partie sans réponse et arrivée là-bas : elle
 * n'apparaît qu'une fois, celle du serveur. La plus ancienne création compte
 * quand il y en a plusieurs : c'est elle que le serveur acceptera.
 *
 * @param {object|null|undefined} activeWorkout
 * @param {Array<object>} entrees les écritures du compte en file
 * @returns {object|null}
 */
export const seanceEnCoursAvecLaFile = (activeWorkout, entrees) => {
    const lues = Array.isArray(entrees) ? entrees : []
    const creation = lues.find(estUneSeanceEnFile)
    const duServeur = activeWorkout ?? null

    if (creation === undefined) {
        return duServeur
    }

    const cle = cleDeLaCreation(creation)

    if (duServeur !== null && cle !== null && duServeur.idempotency_key === cle) {
        return duServeur
    }

    const seance = seanceDeLEntree(creation)
    const lignes = lues.filter(
        (entree) =>
            String(entree?.method ?? '').toLowerCase() === 'post' &&
            /\/api\/v1\/workout-lines$/.test(String(entree?.url ?? '').split(/[?#]/, 1)[0]) &&
            entree.aAnnuler === undefined &&
            nommeLaCreation(entree.data?.workout_id, creation.id),
    )

    return {
        id: seance.id,
        name: seance.name,
        started_at: seance.started_at,
        idempotency_key: cle,
        workout_lines_count: lignes.length,
        enAttenteDe: creation.id,
    }
}

/**
 * Les écritures en file que la coquille trouve dans le stockage, sous les
 * identifiants que la file leur donne (`Utils/listePartagee.js`) : celle-ci
 * relit le même stockage, et une rangée superposée doit porter le même
 * identifiant dans la page et dans la file.
 *
 * @param {() => Storage} stockage
 * @returns {Array<object>}
 */
export const lireLaFileDuStockage = (stockage = () => globalThis.localStorage) =>
    normaliserLesIdentifiants(lireLaListe(stockage, CLE_DE_LA_FILE)).filter(
        (entree) => entree !== null && typeof entree === 'object',
    )

/**
 * La bibliothèque d'exercices et les plafonds d'une série, pour une séance que
 * l'appareil monte sans le serveur : ceux de la page courante quand elle les
 * porte (une séance), sinon la copie hors ligne de la bibliothèque
 * (`/exercises`), sinon celle de la séance en cours ; à défaut, rien. Une
 * copie qui ne se lit pas ne fait rien manquer d'autre.
 *
 * @param {{
 *   props?: Record<string, any> | null,
 *   lireUneCopie?: (adresse: string) => Promise<{ page?: { props?: Record<string, any> } } | null>,
 * }} sources
 * @returns {Promise<{ exercices: Array<object>, bornes: Record<string, number> | null }>}
 */
export const bibliothequeHorsLigne = async ({ props = null, lireUneCopie = async () => null } = {}) => {
    let exercices = Array.isArray(props?.exercises) ? props.exercises : null
    let bornes = props?.bornesDUneSerie ?? null

    const lire = async (adresse) => {
        if (adresse === null) {
            return null
        }

        try {
            return (await lireUneCopie(adresse))?.page?.props ?? null
        } catch {
            return null
        }
    }

    if (exercices === null) {
        const bibliotheque = await lire(CHEMIN_DE_LA_BIBLIOTHEQUE)

        exercices = Array.isArray(bibliotheque?.exercises) ? bibliotheque.exercises : null
    }

    if (exercices === null || bornes === null) {
        const seance = await lire(cleDeLaSeanceEnCours({ props }))

        exercices ??= Array.isArray(seance?.exercises) ? seance.exercises : null
        bornes ??= seance?.bornesDUneSerie ?? null
    }

    return { exercices: exercices ?? [], bornes }
}

/**
 * La page d'Inertia de la séance démarrée sans réseau, prête à pousser
 * (`router.push`) ou à écrire dans la coquille : chaque champ de la page écrit,
 * comme une copie servie (`pageServie`), et tout autre champ de la page
 * courante remis à `undefined` (`champsDeLaPageQuittee`) : rien de la page
 * quittée ne passe dans la séance.
 *
 * - `component` : `Workouts/Show` ; `url` : l'adresse en attente de sa clé ;
 *   `version` : celle de la page courante, celle du code qui tourne ;
 * - `props` : `auth` sans badge à célébrer, la séance (`seanceDeLEntree`, ou
 *   `seance` quand la page la connaît déjà), la bibliothèque, les plafonds et
 *   aucune erreur. Sans la marque `horsLigne` : ce n'est pas une copie ;
 * - `flash` vide ; ni props différées, ni secourues, rien à fusionner, aucune
 *   prop d'une seule fois, aucun défilement infini, aucun état mémorisé,
 *   l'historique gardé ;
 * - `encryptHistory: true`, écrit en dur : la séance d'un compte ne s'écrit
 *   jamais en clair dans l'historique.
 *
 * @param {{
 *   entree?: object,
 *   seance?: object,
 *   auth: unknown,
 *   exercices?: Array<object>,
 *   bornes?: Record<string, number> | null,
 *   pageCourante?: Record<string, unknown> | null,
 * }} options
 * @returns {Record<string, unknown>}
 */
export const pageDeLaSeanceEnAttente = ({
    entree,
    seance,
    auth,
    exercices = [],
    bornes = null,
    pageCourante = null,
}) => {
    const workout = seance ?? seanceDeLEntree(entree)

    return {
        ...champsDeLaPageQuittee(pageCourante),
        component: COMPOSANT_DE_LA_SEANCE,
        url: adresseDUneSeanceEnAttente(workout.idempotency_key),
        version: pageCourante?.version ?? null,
        props: {
            auth: authSansBadge(auth),
            workout,
            exercises: Array.isArray(exercices) ? [...exercices] : [],
            bornesDUneSerie: bornes ?? null,
            errors: {},
        },
        flash: {},
        deferredProps: undefined,
        initialDeferredProps: undefined,
        rescuedProps: [],
        mergeProps: [],
        prependProps: [],
        deepMergeProps: [],
        matchPropsOn: [],
        onceProps: {},
        scrollProps: undefined,
        sharedProps: ['auth'],
        rememberedState: {},
        clearHistory: false,
        encryptHistory: true,
    }
}
