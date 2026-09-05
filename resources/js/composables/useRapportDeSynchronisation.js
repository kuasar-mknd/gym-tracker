import { getCurrentInstance, onUnmounted, ref } from 'vue'
import SyncService from '@/Utils/SyncService'
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
    let editErrorTimer = null

    const reportEditFailure = (message) => {
        editError.value = message
        triggerHaptic('error')

        if (editErrorTimer) {
            clearTimeout(editErrorTimer)
        }

        editErrorTimer = setTimeout(() => {
            editError.value = null
        }, 6000)
    }

    if (getCurrentInstance()) {
        onUnmounted(() => clearTimeout(editErrorTimer))
    }

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

    const handleSyncAuthRequired = (event) => {
        const pending = event.detail?.pending ?? 0
        reportEditFailure(
            `Ta session a expiré : reconnecte-toi, ${pending > 1 ? `tes ${pending} modifications en attente` : 'ta modification en attente'} repartir${pending > 1 ? 'ont' : 'a'} ensuite.`,
        )
    }

    const handleSyncStorageFull = (event) => {
        const pending = event.detail?.pending ?? 0
        reportEditFailure(
            `Le stockage du téléphone est plein : ${pending} modification${pending > 1 ? 's' : ''} en attente ne survivr${pending > 1 ? 'ont' : 'a'} pas à un rechargement.`,
        )
    }

    /**
     * Une écriture refusée par le serveur : une série existante se marque, une
     * création (série ou ligne) n'a pas de rangée à marquer, alors le message
     * porte l'identification à sa place.
     */
    const handleSyncFailure = (event) => {
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

    /** Annonce une fois ce qui a été refusé pendant que la page était absente. */
    const markQueuedFailuresOnMount = () => {
        const failures = SyncService.failedRequests()

        if (failures.length === 0) {
            return
        }

        failures.forEach((failure) => handleSyncFailure({ detail: { url: failure.url, data: failure.data } }))

        SyncService.clearFailedRequests()
    }

    return {
        unsyncedSetIds,
        clearUnsynced,
        markUnsynced,
        editError,
        reportEditFailure,
        reportSyncFailure,
        describeFailedCreate,
        handleSyncAuthRequired,
        handleSyncStorageFull,
        handleSyncFailure,
        markQueuedFailuresOnMount,
    }
}
