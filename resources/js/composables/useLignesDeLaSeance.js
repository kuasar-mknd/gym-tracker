import { computed, onUnmounted } from 'vue'
import SyncService from '@/Utils/SyncService'
import { useConfirmation } from '@/composables/useConfirmation'

/**
 * Les lignes de la seance : ajouter un exercice, le retirer apres avoir pose
 * la question, et attendre la file hors ligne quand sa creation y dort.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   queuedLineIds: Set<string>,
 *   nouvelIdTemporaire: () => string,
 *   newRowKey: () => string,
 *   localExercises: import('vue').Ref<Array<object>>,
 *   showAddExercise: import('vue').Ref<boolean>,
 *   oublierLesEcrituresDeLaLigne: (line: object) => void,
 *   reportSyncFailure: (message: string) => void,
 * }} page
 */
export const useLignesDeLaSeance = ({
    localWorkout,
    pendingIds,
    queuedLineIds,
    nouvelIdTemporaire,
    newRowKey,
    localExercises,
    showAddExercise,
    oublierLesEcrituresDeLaLigne,
    reportSyncFailure,
}) => {
    const replayListeners = new Set()

    /**
     * Resolves to the id a queued create eventually produced, once the queue
     * actually drains.
     *
     * Without this, a create that went offline resolved to "no such row", so a set
     * added to an exercise that was still queued was refused on the spot and never
     * revisited: the queue drained, the exercise appeared on the server, and the
     * set belonged to nobody. It is the same shape as the placeholder-id bug one
     * level up, and it outlived the fix for it.
     */
    const awaitReplay = (queueId) =>
        new Promise((resolve) => {
            if (!queueId) {
                resolve(null)

                return
            }

            const onReplayed = (event) => {
                if (event.detail?.queueId !== queueId) return

                window.removeEventListener('sync:replayed', onReplayed)
                replayListeners.delete(onReplayed)
                resolve(event.detail.data?.id ?? null)
            }

            replayListeners.add(onReplayed)
            window.addEventListener('sync:replayed', onReplayed)
        })

    // ⚡ Perf: addExercise via API call + optimistic UI instead of Inertia redirect
    const addExercise = (exerciseId) => {
        const exercise = localExercises.value.find((e) => e.id === exerciseId)
        if (!exercise) return

        // Optimistic: add line immediately
        const tempLine = {
            id: nouvelIdTemporaire(),
            _rowKey: newRowKey(),
            exercise_id: exerciseId,
            exercise: { ...exercise },
            sets: [],
            order: localWorkout.value.workout_lines.length,
            notes: null,
            recommended_values: null,
        }
        localWorkout.value.workout_lines.push(tempLine)
        showAddExercise.value = false

        const creation = SyncService.post(route('api.v1.workout-lines.store'), {
            workout_id: localWorkout.value.id,
            exercise_id: exerciseId,
        })
            .then((response) => {
                const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === tempLine.id)
                const created = response.data?.data

                if (!created) {
                    return null
                }

                if (idx !== -1) {
                    /**
                     * Mutated in place, never replaced.
                     *
                     * Assigning a new object into the slot fixed one bug and made a
                     * subtler one: addSet captures `line` when the user taps, and
                     * pushes its optimistic set into THAT object's sets array. Swap
                     * the slot for a fresh object with a fresh array and addSet is
                     * left holding a detached copy — its own findIndex then never
                     * finds the set again, so the row stays on a placeholder id for
                     * as long as the page lives.
                     *
                     * Keeping the object and the array identity means every closure,
                     * debounce timer and v-model binding already pointing at this
                     * line stays pointing at the real one.
                     */
                    const { sets: createdSets, ...lineFields } = created
                    const line = localWorkout.value.workout_lines[idx]

                    Object.assign(line, lineFields)

                    // A line the server has just created has no sets of its own, but
                    // a replayed create can return one that does.
                    for (const set of createdSets ?? []) {
                        if (!line.sets.some((existing) => existing.id === set.id)) line.sets.push(set)
                    }
                }

                return created.id
            })
            .catch((err) => {
                if (err.isOffline) {
                    // Queued, not lost. Waiting for the drain to report the real id
                    // is what lets a set added onto this exercise reach the server
                    // at all; answering null here stranded it permanently.
                    queuedLineIds.add(tempLine.id)

                    return awaitReplay(err.queueId)
                }

                const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === tempLine.id)
                if (idx !== -1) localWorkout.value.workout_lines.splice(idx, 1)
                reportSyncFailure('L’exercice n’a pas pu être ajouté à la séance. Réessaie.')

                return null
            })

        pendingIds.track(tempLine.id, creation)
    }

    /**
     * Retire la ligne de l'ecran tout de suite, et du serveur des que sa creation
     * est retombee : supprimer `temp-1` rendait 404 et laissait la ligne la-bas.
     */
    const retirerLaLigne = (line) => {
        const lineId = line.id

        oublierLesEcrituresDeLaLigne(line)

        const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === lineId)
        const removedLine = idx !== -1 ? localWorkout.value.workout_lines.splice(idx, 1)[0] : null

        pendingIds
            .resolve(lineId)
            .then((realLineId) => {
                if (realLineId === null) {
                    pendingIds.forget(lineId)

                    return
                }

                return SyncService.delete(route('api.v1.workout-lines.destroy', { workout_line: realLineId }))
            })
            .catch((err) => {
                if (!err.isOffline && removedLine) {
                    localWorkout.value.workout_lines.splice(idx, 0, removedLine)
                    reportSyncFailure('L’exercice n’a pas pu être retiré de la séance. Réessaie.')
                }
            })
    }

    /*
     * La question d'abord, la ligne ensuite : le dialogue se referme avant le
     * retrait, sinon il resterait ouvert sur une liste qui a deja bouge sous lui.
     */
    const {
        cible: ligneARetirer,
        ouvert: retraitDemande,
        demander: demanderLeRetrait,
        annuler: annulerLeRetrait,
        confirmer: confirmerLeRetrait,
    } = useConfirmation((ligne, termine) => {
        termine()
        retirerLaLigne(ligne)
    })

    const titreDuRetrait = computed(() => `Supprimer ${ligneARetirer.value?.exercise?.name || "l'exercice"} ?`)

    const removeLine = (lineId) => {
        const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)

        if (line) {
            demanderLeRetrait(line)
        }
    }

    onUnmounted(() => {
        // One per create still waiting on the offline queue; the page may well be
        // left before the drain ever comes.
        replayListeners.forEach((listener) => window.removeEventListener('sync:replayed', listener))
        replayListeners.clear()
    })

    return {
        addExercise,
        removeLine,
        ligneARetirer,
        retraitDemande,
        titreDuRetrait,
        annulerLeRetrait,
        confirmerLeRetrait,
    }
}
