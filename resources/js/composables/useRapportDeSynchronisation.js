import { computed, getCurrentInstance, onMounted, onUnmounted, ref } from 'vue'
import SyncService from '@/Utils/SyncService'
import { estUneCreationDeSeance } from '@/Utils/seanceEtFile'
import { triggerHaptic } from '@/composables/useHaptics'

/**
 * Ce que la page de séance dit quand une écriture n'arrive pas au serveur.
 *
 * Chaque chemin d'échec faisait la même chose : remettre la rangée optimiste en
 * place, et vibrer. Sur un téléphone c'est une vibration sans mot, sur un
 * bureau rien du tout. Deux canaux, donc : le toast que la mise en page rend
 * déjà depuis `flash.error`, et un message posé six secondes au-dessus des
 * séries pour une correction refusée. Hors ligne est exclu à dessein : la file
 * s'en charge, la valeur reste à l'écran, l'utilisateur n'a rien à faire.
 *
 * @param {{
 *   page: { props: { flash?: { error?: string } } },
 *   exercices: () => Array<{ id: unknown, name: string }>,
 *   lignes: () => Array<{ id: unknown, exercise?: { name?: string }, sets?: Array<unknown> }> | undefined,
 * }} contexte
 */
export const useRapportDeSynchronisation = ({ page, exercices, lignes }) => {
    /**
     * Les séries dont la valeur est à l'écran mais pas en base. Un refus reste
     * visible et marqué : jeter une saisie sans rien dire serait pire.
     */
    const unsyncedSetIds = ref(new Set())

    const clearUnsynced = (...setIds) => {
        setIds.forEach((setId) => unsyncedSetIds.value.delete(String(setId)))
    }

    const markUnsynced = (setId) => {
        unsyncedSetIds.value.add(String(setId))
    }

    const reportSyncFailure = (message) => {
        const flash = page.props.flash ?? (page.props.flash = {})

        flash.error = message
        triggerHaptic('error')
    }

    const editError = ref(null)
    const editErrorPersistante = ref(false)
    const editErrorSessionExpiree = ref(false)
    let editErrorTimer = null

    /** Les refus ordinaires survenus pendant qu'un message persistant occupait la place. */
    let refusEnAttente = []
    let sessionExpiree = null
    let stockagePlein = null

    /**
     * Un message de la file (session expirée, stockage plein) reste jusqu'à sa
     * fermeture : des saisies attendent encore, et six secondes ne suffisent
     * pas à le lire. Un refus ordinaire ne le remplace pas : il attend la
     * fermeture, puis s'affiche à son tour.
     */
    const reportEditFailure = (message, { persistante = false, avecReconnexion = false } = {}) => {
        triggerHaptic('error')

        if (editErrorPersistante.value && !persistante) {
            if (!refusEnAttente.includes(message)) {
                refusEnAttente.push(message)
            }

            return
        }

        clearTimeout(editErrorTimer)

        editError.value = message
        editErrorPersistante.value = persistante
        editErrorSessionExpiree.value = avecReconnexion

        if (!persistante) {
            editErrorTimer = setTimeout(() => {
                editError.value = null
            }, 6000)
        }
    }

    const fermerEditError = () => {
        clearTimeout(editErrorTimer)
        editError.value = null
        editErrorPersistante.value = false
        editErrorSessionExpiree.value = false
        sessionExpiree = null
        stockagePlein = null

        const suite = refusEnAttente.join(' ')
        refusEnAttente = []

        if (suite) {
            reportEditFailure(suite)
        }
    }

    if (getCurrentInstance()) {
        onUnmounted(() => clearTimeout(editErrorTimer))
    }

    /** Ce que `ErreurDeSaisie` reçoit : le message, sa persistance, le lien et la fermeture. */
    const erreurDeSaisie = computed(() => ({
        message: editError.value,
        persistant: editErrorPersistante.value,
        reconnexion: editErrorSessionExpiree.value,
        onFermer: fermerEditError,
    }))

    /** La charge utile, qu'elle ait été mise en file en objet ou déjà sérialisée. */
    const payloadOf = (data) => {
        if (typeof data !== 'string') {
            return data ?? {}
        }

        try {
            return JSON.parse(data) ?? {}
        } catch {
            return {}
        }
    }

    const exerciseNamed = (exerciseId) =>
        exercices().find((exercise) => String(exercise.id) === String(exerciseId))?.name

    /**
     * Nomme ce que le serveur a refusé ; la formule vague ne sert que quand la
     * charge utile ne se rattache plus à rien à l'écran.
     */
    const describeFailedCreate = (url, data) => {
        const payload = payloadOf(data)
        const generic = "Un élément de la séance n'a pas pu être enregistré."

        if (/\/workout-lines(\?|$)/.test(url)) {
            const name = exerciseNamed(payload.exercise_id)

            return name ? `« ${name} » n'a pas pu être ajouté à la séance.` : "Un exercice n'a pas pu être ajouté."
        }

        const line = (lignes() ?? []).find((candidate) => String(candidate.id) === String(payload.workout_line_id))

        if (!line) {
            return generic
        }

        const position = (line.sets?.length ?? 0) || 1

        return `La série ${position} de « ${line.exercise?.name ?? 'cet exercice'} » n'a pas pu être enregistrée.`
    }

    /**
     * Session expirée et stockage plein se disent ensemble : le second
     * n'efface pas le premier, et quand le téléphone ne garde plus rien, le
     * message n'offre pas le lien de connexion, que la navigation complète
     * rendrait fatal à ce qui n'est qu'en mémoire.
     */
    const direLaFile = () => {
        const pending = Math.max(sessionExpiree ?? 0, stockagePlein ?? 0)
        const pluriel = pending > 1

        if (sessionExpiree !== null && stockagePlein !== null) {
            reportEditFailure(
                `Ta session a expiré et le stockage du téléphone est plein : ${pending} modification${pluriel ? 's' : ''} en attente ne survivr${pluriel ? 'ont' : 'a'} pas à un rechargement. Reconnecte-toi sans recharger la page.`,
                { persistante: true },
            )
        } else if (sessionExpiree !== null) {
            reportEditFailure(
                `Ta session a expiré : reconnecte-toi, ${pluriel ? `tes ${pending} modifications en attente` : 'ta modification en attente'} repartir${pluriel ? 'ont' : 'a'} ensuite.`,
                { persistante: true, avecReconnexion: true },
            )
        } else {
            reportEditFailure(
                `Le stockage du téléphone est plein : ${pending} modification${pluriel ? 's' : ''} en attente ne survivr${pluriel ? 'ont' : 'a'} pas à un rechargement.`,
                { persistante: true },
            )
        }
    }

    const handleSyncAuthRequired = (event) => {
        sessionExpiree = event.detail?.pending ?? 0
        direLaFile()
    }

    const handleSyncStorageFull = (event) => {
        stockagePlein = event.detail?.pending ?? 0
        direLaFile()
    }

    /**
     * Une écriture refusée par le serveur : une série existante se marque, une
     * création (série ou ligne) n'a pas de rangée à marquer, alors le message
     * porte l'identification à sa place.
     *
     * Une écriture refusée faute de parent (`parentRefuse`) ne dit rien
     * d'elle-même (#2049) : la séance démarrée sans réseau que le serveur a
     * refusée l'emporte, et le layout annonce ce refus une fois, avec le
     * nombre d'écritures emportées (`useSeanceEnCoursDeLAppareil`). Un message
     * par série disait le contraire de ce qui s'était passé.
     */
    const handleSyncFailure = (event) => {
        if (event.detail?.parentRefuse === true) {
            return
        }

        const url = event.detail?.url ?? ''
        const setId = /\/sets\/(\d+)/.exec(url)?.[1]

        if (setId) {
            markUnsynced(setId)

            return
        }

        if (/\/(sets|workout-lines)(\?|$)/.test(url)) {
            reportEditFailure(describeFailedCreate(url, event.detail?.data))
        }
    }

    /**
     * Ce que la page dit elle-même des refus : pas celui d'une séance démarrée
     * sans réseau, ni ce qu'il emporte, que le layout annonce (#2049).
     *
     * @param {object} refus
     */
    const estPourLaPage = (refus) => refus?.parentRefuse !== true && !estUneCreationDeSeance(refus)

    /**
     * Annonce une fois ce qui a été refusé pendant que la page était absente,
     * puis l'oublie, et cela seulement : la page effaçait tous les refus du
     * compte, et celui d'une séance démarrée sans réseau disparaissait avant
     * que le layout l'ait dit (#2049).
     */
    const markQueuedFailuresOnMount = () => {
        const failures = SyncService.failedRequests().filter(estPourLaPage)

        if (failures.length === 0) {
            return
        }

        failures.forEach((failure) => handleSyncFailure({ detail: { url: failure.url, data: failure.data } }))

        SyncService.oublierDesRefus(failures.map((failure) => failure.id))
    }

    /*
     * La page branchait ces trois ecouteurs a son montage : ils appartiennent au
     * rapport, qui les pose et les retire lui-meme.
     */
    if (getCurrentInstance()) {
        onMounted(() => {
            window.addEventListener('sync:failed', handleSyncFailure)
            window.addEventListener('sync:auth-required', handleSyncAuthRequired)
            window.addEventListener('sync:storage-full', handleSyncStorageFull)
            markQueuedFailuresOnMount()
        })

        onUnmounted(() => {
            window.removeEventListener('sync:failed', handleSyncFailure)
            window.removeEventListener('sync:auth-required', handleSyncAuthRequired)
            window.removeEventListener('sync:storage-full', handleSyncStorageFull)
        })
    }

    return {
        unsyncedSetIds,
        clearUnsynced,
        markUnsynced,
        editError,
        erreurDeSaisie,
        editErrorPersistante,
        editErrorSessionExpiree,
        fermerEditError,
        reportEditFailure,
        reportSyncFailure,
        describeFailedCreate,
        handleSyncAuthRequired,
        handleSyncStorageFull,
        handleSyncFailure,
        markQueuedFailuresOnMount,
    }
}
