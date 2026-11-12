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

    /** Les refus ordinaires survenus pendant qu'un message persistant occupait la place, chacun gardé. */
    let refusEnAttente = []
    let sessionExpiree = null
    let stockagePlein = null
    /** Les écritures que la porte fermée a fait refuser depuis que le message est posé. */
    let ecrituresEcartees = 0

    /**
     * Un message de la file (session expirée, stockage plein) reste jusqu'à sa
     * fermeture : des saisies attendent encore, et six secondes ne suffisent
     * pas à le lire. Un refus ordinaire ne le remplace pas : il attend la
     * fermeture, puis s'affiche à son tour.
     */
    const reportEditFailure = (message, { persistante = false, avecReconnexion = false, duree = 6000 } = {}) => {
        if (editErrorPersistante.value && !persistante) {
            refusEnAttente.push(message)

            return
        }

        triggerHaptic('error')
        clearTimeout(editErrorTimer)

        editError.value = message
        editErrorPersistante.value = persistante
        editErrorSessionExpiree.value = avecReconnexion

        if (!persistante) {
            editErrorTimer = setTimeout(() => {
                editError.value = null
            }, duree)
        }
    }

    /** Les refus mis de côté, dits d'un coup : deux refus au même texte restent deux, comptés. */
    const resumerLesRefus = (messages) => {
        if (messages.length === 1) {
            return messages[0]
        }

        const comptes = new Map()
        messages.forEach((message) => comptes.set(message, (comptes.get(message) ?? 0) + 1))

        const detail = [...comptes].map(([message, fois]) => (fois > 1 ? `${message} (×${fois})` : message))

        return `${messages.length} refus : ${detail.join(' ')}`
    }

    const fermerEditError = () => {
        clearTimeout(editErrorTimer)
        editError.value = null
        editErrorPersistante.value = false
        editErrorSessionExpiree.value = false
        sessionExpiree = null
        stockagePlein = null
        ecrituresEcartees = 0

        const suite = refusEnAttente
        refusEnAttente = []

        if (suite.length > 0) {
            reportEditFailure(resumerLesRefus(suite), { duree: 6000 + 3000 * (suite.length - 1) })
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

    /** Session expirée et stockage plein se disent ensemble ; le lien de connexion est un `Link` d'Inertia : la file en mémoire survit. */
    const direLaFile = () => {
        const attente = Math.max(sessionExpiree ?? 0, stockagePlein ?? 0)
        const pluriel = attente > 1
        const ecartees = ecrituresEcartees
        const sort = `${attente} modification${pluriel ? 's' : ''} en attente ne survivr${pluriel ? 'ont' : 'a'} pas à un rechargement`

        if (sessionExpiree !== null && stockagePlein !== null) {
            reportEditFailure(
                `Ta session a expiré et le stockage du téléphone est plein : ${sort}. Reconnecte-toi sans recharger la page.${ecartees > 0 ? ` ${ecartees} modification${ecartees > 1 ? 's ont' : ' a'} été écartée${ecartees > 1 ? 's' : ''}.` : ''}`,
                { persistante: true, avecReconnexion: true },
            )
        } else if (sessionExpiree !== null) {
            const suite =
                attente === 0
                    ? '.'
                    : ` ; ${pluriel ? `les ${attente} autres modifications en attente repartiront` : "l'autre modification en attente repartira"} ensuite.`
            const refus =
                ecartees > 0
                    ? ecartees > 1
                        ? `${ecartees} modifications n'ont pas pu être envoyées et ont été écartées`
                        : "Une modification n'a pas pu être envoyée et a été écartée"
                    : null

            reportEditFailure(
                refus === null
                    ? `Ta session a expiré : reconnecte-toi, ${pluriel ? `tes ${attente} modifications en attente` : 'ta modification en attente'} repartir${pluriel ? 'ont' : 'a'} ensuite.`
                    : `Ta session a expiré : reconnecte-toi. ${refus}${suite}`,
                { persistante: true, avecReconnexion: true },
            )
        } else {
            reportEditFailure(`Le stockage du téléphone est plein : ${sort}.`, { persistante: true })
        }
    }

    const handleSyncAuthRequired = (event) => {
        const refusee = event.detail?.refusee === true
        const enAttente = event.detail?.pending ?? 0

        if (refusee) {
            ecrituresEcartees += 1
        }

        sessionExpiree = refusee ? Math.max(0, enAttente - 1) : enAttente
        direLaFile()
    }

    const handleSyncStorageFull = (event) => {
        stockagePlein = event.detail?.pending ?? 0
        direLaFile()
    }

    /** La file vide, les messages de porte fermée et de stockage plein n'ont plus d'objet : ils se ferment. */
    const handleSyncReplayed = () => {
        if ((sessionExpiree === null && stockagePlein === null) || SyncService.enAttente() > 0) {
            return
        }

        const surLeMessage = document.activeElement?.closest('[dusk="set-edit-error"]') != null

        fermerEditError()

        if (surLeMessage) {
            document.getElementById('main-content')?.focus({ preventScroll: true })
        }
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
            window.addEventListener('sync:replayed', handleSyncReplayed)
            markQueuedFailuresOnMount()
        })

        onUnmounted(() => {
            window.removeEventListener('sync:failed', handleSyncFailure)
            window.removeEventListener('sync:auth-required', handleSyncAuthRequired)
            window.removeEventListener('sync:storage-full', handleSyncStorageFull)
            window.removeEventListener('sync:replayed', handleSyncReplayed)
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
        handleSyncReplayed,
        handleSyncFailure,
        markQueuedFailuresOnMount,
    }
}
