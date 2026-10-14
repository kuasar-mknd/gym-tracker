import { computed, onUnmounted } from 'vue'
import SyncService from '@/Utils/SyncService'
import { creerLesAttentesDeRejeu } from '@/Utils/attentesDeRejeu'
import { entreeDeLaRangee } from '@/Utils/seanceEtFile'
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
    /**
     * Les créations de lignes parties dans la file hors ligne, qui attendent le
     * vidage pour apprendre ce qu'elles sont devenues.
     */
    const attentes = creerLesAttentesDeRejeu()

    /**
     * La ligne que le serveur a créée prend la place de la provisoire, que la
     * réponse vienne tout de suite ou du vidage de la file. Au vidage, la
     * ligne gardait son identifiant provisoire : un rafraîchissement des
     * props l'affichait à côté de la copie du serveur (#1962, comme #1960
     * pour une série).
     *
     * @param {string} idProvisoire l'identifiant que la ligne porte à l'écran
     * @param {object|null|undefined} created la ligne telle que le serveur l'a créée
     * @returns {number|string|null} l'identifiant réel, ou null sans ligne créée
     */
    const adopterLaLigne = (idProvisoire, created) => {
        const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === idProvisoire)

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
    }

    /**
     * Une ligne dont la création attend dans la file hors ligne : celle qu'on
     * vient d'y mettre, ou celle que la file tenait déjà à l'ouverture de la
     * page (`useSeanceEtSaFile`, #2048). Elle attend le vidage pour apprendre
     * ce qu'elle est devenue.
     *
     * @param {object} ligne la rangée provisoire à l'écran
     * @param {string|undefined} queueId l'entrée de file qui la crée
     * @returns {Promise<number|string|null>} l'identifiant réel, ou null
     */
    const suivreUneLigneEnFile = (ligne, queueId) => {
        // Queued, not lost. Waiting for the drain to report the real id
        // is what lets a set added onto this exercise reach the server
        // at all; answering null here stranded it permanently.
        queuedLineIds.add(ligne.id)

        /*
         * Ses séries nomment désormais cette entrée de file, et y
         * entrent derrière elle sans attendre le vidage (#1962).
         */
        if (queueId) {
            pendingIds.noterEnFile(ligne.id, queueId)
        }

        /*
         * Without this, a create that went offline resolved to "no
         * such row", so a set added to an exercise that was still
         * queued was refused on the spot and never revisited. Null
         * when the drain refuses it or the line is withdrawn first.
         */
        return attentes.attendre(queueId).then((rejeu) => adopterLaLigne(ligne.id, rejeu?.data))
    }

    /*
     * Les lignes que la file tenait déjà quand la page s'est ouverte, posées
     * par-dessus la séance du serveur, se suivent comme celle qu'on vient d'y
     * mettre : sans cela, ses séries ne sauraient pas derrière quelle entrée se
     * ranger, son retrait ne sortirait pas sa création de la file, et elle
     * garderait son identifiant provisoire après le vidage (#2048).
     */
    for (const ligne of Array.isArray(localWorkout.value.workout_lines) ? localWorkout.value.workout_lines : []) {
        const queueId = entreeDeLaRangee(ligne.id)

        if (queueId !== null) {
            pendingIds.track(ligne.id, suivreUneLigneEnFile(ligne, queueId))
        }
    }

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
            .then((response) => adopterLaLigne(tempLine.id, response.data?.data))
            .catch((err) => {
                if (err.isOffline) {
                    return suivreUneLigneEnFile(tempLine, err.queueId)
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

        /*
         * Une ligne dont la création attend encore en file sort de la file avec
         * les séries qui en dépendent : rien n'est à supprimer sur le serveur,
         * et sa création se règle à null. Partie, elle se supprime par
         * l'identifiant qu'elle rend. Tentée sans réponse, elle a pu être créée
         * là-bas : la file la garde, et la supprime au vidage (#1960).
         */
        const retirerSaCreation = () => {
            const fileDeLaLigne = pendingIds.fileDe(lineId)

            if (fileDeLaLigne !== null) {
                SyncService.retirerDeLaFile(fileDeLaLigne, {
                    // L'identifiant que le serveur aura émis : le vidage le pose à la place de `realId`.
                    annulerPar: (realId) => route('api.v1.workout-lines.destroy', { workout_line: realId }),
                })
            }
        }

        retirerSaCreation()

        /*
         * Retirée pendant que le premier envoi de sa création volait encore, la
         * ligne attendait le vidage en mémoire seulement : si cet envoi finissait
         * en file, un rechargement la laissait partir, et l'exercice retiré
         * revenait sur le serveur. On attend donc ce premier envoi, et s'il a
         * fini en file, elle en sort (#1962).
         */
        pendingIds
            .reference(lineId)
            .then(() => {
                retirerSaCreation()

                return pendingIds.resolve(lineId)
            })
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
        attentes.oublierTout()
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
