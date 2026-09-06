import SyncService from '@/Utils/SyncService'
import { NUMERIC_SET_FIELDS } from '@/composables/useBrouillonsDeSeries'

/**
 * La naissance et le retrait d'une série : l'ajout optimiste avec ce que
 * l'exercice mesure, la chaîne de création qui fait attendre le second ajout
 * derrière le premier, le retrait remis en place si le serveur refuse, et ce
 * qu'une ligne retirée laisse derrière elle.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   queuedLineIds: Set<string>,
 *   nouvelIdTemporaire: () => string,
 *   newRowKey: () => string,
 *   rowKey: (rangee: object) => string|number,
 *   fieldWrites: { forget: (cle: string) => void },
 *   deleteSet: (setId: unknown) => Promise<unknown>,
 *   oublierLesRafales: (setId: unknown) => void,
 *   oublierLaSerie: (setId: unknown) => void,
 *   markUnsynced: (setId: unknown) => void,
 *   clearUnsynced: (setId: unknown, realId?: unknown) => void,
 *   reportSyncFailure: (message: string) => void,
 * }} page
 */
export const useAjoutEtRetraitDeSerie = ({
    localWorkout,
    pendingIds,
    queuedLineIds,
    nouvelIdTemporaire,
    newRowKey,
    rowKey,
    fieldWrites,
    deleteSet,
    oublierLesRafales,
    oublierLaSerie,
    markUnsynced,
    clearUnsynced,
    reportSyncFailure,
}) => {
    /**
     * What each kind of exercise measures — the same split the set row renders.
     *
     * A strength set has a weight and reps; a cardio one a distance and a duration;
     * a timed one only a duration. Nothing else about a set is a measurement, and
     * writing the other fields anyway put numbers in rows that have no business
     * holding them.
     */
    const MEASURED_FIELDS_BY_TYPE = {
        strength: ['weight', 'reps'],
        cardio: ['distance_km', 'duration_seconds'],
        timed: ['duration_seconds'],
    }

    /**
     * An unknown type renders no inputs at all, so it gets the strength pair rather
     * than an empty set that could never be filled in.
     */
    const measuredFieldsFor = (exercise) => MEASURED_FIELDS_BY_TYPE[exercise?.type] ?? MEASURED_FIELDS_BY_TYPE.strength

    /**
     * The last set-create issued for each exercise, so the next one can queue behind
     * it instead of racing it.
     *
     * A set has no order of its own — the database hands them back by id, and the id
     * is decided by whichever INSERT reaches the server first. Tapping "Ajouter une
     * série" twice in quick succession sent two POSTs at once, so the second set
     * could be written first and come back BEFORE the first one on the next load.
     * Sets appearing in an order the user did not create them in, specifically when
     * going fast, is exactly the reported symptom.
     *
     * Indexee sur l'identite stable de la ligne, pas sur son identifiant : celui-ci
     * passe de provisoire a reel quand sa creation retombe, et deux series ajoutees
     * de part et d'autre de cet instant doivent partager une seule chaine.
     *
     * Elle l'etait sur l'OBJET ligne, au motif que « addExercise mute la ligne en
     * place et ne la remplace jamais ». C'etait vrai d'`addExercise` ; ca ne l'est
     * pas de `mergeServerWorkout`, qui reconstruit chaque ligne par
     * `JSON.parse(JSON.stringify(...))`. Apres un rafraichissement de props, la
     * serie suivante ouvrait donc une chaine neuve et sa creation partait sans
     * attendre la precedente — avec un `workout_line_id` qui pouvait encore etre
     * provisoire.
     *
     * `rowKey()` est la meme identite que celle qui indexe les ecritures de
     * validation, et elle survit desormais a la fusion. Une `Map` plutot qu'une
     * `WeakMap` : la cle est une chaine, donc elle ne se ramasse pas toute seule.
     * L'entree est oubliee au retrait de la ligne.
     *
     * @type {Map<string|number, Promise>}
     */
    const setCreateChains = new Map()

    const addSet = (lineId) => {
        const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)
        if (!line) return

        const lastSet = line.sets?.length > 0 ? line.sets[line.sets.length - 1] : null
        const recommendation = line?.recommended_values ?? null

        const prefilled = {
            weight: lastSet ? lastSet.weight : (recommendation?.weight ?? 0),
            reps: lastSet ? lastSet.reps : (recommendation?.reps ?? 10),
            distance_km: lastSet ? lastSet.distance_km : (recommendation?.distance_km ?? 0),
            duration_seconds: lastSet ? lastSet.duration_seconds : (recommendation?.duration_seconds ?? 30),
        }

        /**
         * Only what this kind of exercise actually measures.
         *
         * All four were filled in and sent for every set regardless of type, so a
         * cardio set was written with `reps: 10` and a weight of 0, and a timed one
         * with those plus `distance_km: 0` — none of which the screen even shows for
         * that exercise, and none of which the user typed. A reported 10 appearing
         * out of nowhere is this: 10 is the reps pre-fill, and it was being written
         * to rows whose exercise has no reps.
         *
         * The pre-fills that remain are the ones the user can see and correct.
         */
        const measured = measuredFieldsFor(line.exercise)
        const values = Object.fromEntries(measured.map((field) => [field, prefilled[field]]))

        /**
         * Optimistic: add set immediately with a temp id. It carried the line id
         * too, which nothing ever read — the set already lives inside its line —
         * while being exactly the placeholder that must not reach a payload.
         */
        if (!Array.isArray(line.sets)) line.sets = []

        line.sets.push({
            id: nouvelIdTemporaire(),
            _rowKey: newRowKey(),
            is_completed: false,
            is_warmup: false,
            ...values,
        })

        /**
         * Read back out of the array, not kept from before the push.
         *
         * `line.sets` lives inside a reactive ref, so what it hands back is a proxy;
         * the literal above is the raw object behind it. Writing to the raw one —
         * which is what holding on to it did — changes the value without tripping
         * any tracker, so nothing re-renders.
         *
         * Invisible while everything works, because the row is already on screen
         * showing what the user typed. It bit when the correction PATCH that
         * follows was refused: `markUnsynced` then updated state correctly and the
         * "not saved" badge never appeared, so a set the server had not kept looked
         * saved. See #1397.
         */
        const tempSet = line.sets[line.sets.length - 1]

        /**
         * The line may itself still be a placeholder — adding a set right after the
         * exercise is the most ordinary thing to do on this screen. Sending
         * `workout_line_id: "temp-1"` earned a 422 live, and when the same payload
         * was replayed from the offline queue it took the set with it.
         */
        // The exercise is queued rather than in flight, so this set is going nowhere
        // until the drain. Say so now rather than leaving the row looking saved.
        if (queuedLineIds.has(lineId)) {
            markUnsynced(tempSet.id)
        }

        const chaineDeLigne = rowKey(line)
        const previousCreate = setCreateChains.get(chaineDeLigne) ?? Promise.resolve()

        const creation = previousCreate
            .then(() => pendingIds.resolve(lineId))
            .then((realLineId) => {
                if (realLineId === null) {
                    markUnsynced(tempSet.id)

                    return null
                }

                /*
                 * La ligne vient de naitre : sa recommandation est arrivee avec la
                 * reponse de creation, APRES que cette serie a ete ajoutee avec les
                 * valeurs par defaut de l'ecran. Une serie que l'utilisateur n'a pas
                 * touchee prend maintenant ce que le serveur propose ; un champ deja
                 * corrige garde sa saisie. Sans cela, la premiere serie partait a
                 * 0 kg, les suivantes la copiaient, et ce 0 devenait l'historique de
                 * la seance d'apres. Voir #1677.
                 */
                if (!lastSet && recommendation === null && line.recommended_values) {
                    for (const field of measured) {
                        if (tempSet[field] === values[field]) {
                            tempSet[field] = line.recommended_values[field] ?? tempSet[field]
                        }
                    }
                }

                const sent = {
                    is_completed: false,
                    ...Object.fromEntries(measured.map((field) => [field, tempSet[field]])),
                }

                return SyncService.post(route('api.v1.sets.store'), {
                    workout_line_id: realLineId,
                    ...sent,
                }).then((response) => {
                    const created = response.data?.data

                    if (!created) {
                        return null
                    }

                    /**
                     * The server owns the identity, the user owns the values.
                     *
                     * Assigning the server's copy over the row reverted whatever the
                     * user typed while the create was in flight — and typing into a
                     * set the instant you add it is the normal way to use this
                     * screen. The payload left with the old numbers, so the server's
                     * answer necessarily carries them back; taking it wholesale
                     * overwrote the new ones on screen and left the database holding
                     * values the user had already corrected.
                     *
                     * Mutating in place also keeps the row identity that v-model and
                     * the per-set debounce timers are bound to.
                     */
                    /*
                     * Les valeurs, et elles seules : `is_completed` est exclue.
                     *
                     * La charge utile envoyee la porte — a `false` — donc cocher
                     * pendant que la creation etait en vol la faisait entrer dans
                     * ce diff. Ce PATCH-ci part SANS sequenceur ni file : il
                     * courait contre la chaine de completion, qui elle est
                     * ordonnee, et rien n'arbitrait entre les deux sinon l'ordre
                     * d'arrivee au serveur. L'ecran pouvait avoir raison pendant
                     * que la base gardait la valeur du perdant.
                     *
                     * Il etait de toute facon redondant : `toggleSetCompletion` est
                     * garee sur `pendingIds.resolve(set.id)`, qui se resout sur
                     * cette meme promesse de creation. Son ecriture ordonnee part
                     * donc a l'instant ou la creation retombe, et elle porte deja
                     * la validation.
                     */
                    const edited = Object.fromEntries(
                        Object.keys(sent)
                            .filter((field) => field !== 'is_completed' && tempSet[field] !== sent[field])
                            .map((field) => [field, tempSet[field]]),
                    )

                    const realSetId = created.id

                    // It is in the database now, under either id it has worn.
                    clearUnsynced(tempSet.id, realSetId)

                    tempSet.id = realSetId
                    tempSet.created_at = created.created_at
                    tempSet.updated_at = created.updated_at
                    tempSet.personal_record = created.personal_record

                    // Typed after the payload left, so the server has never heard it.
                    if (Object.keys(edited).length > 0) {
                        SyncService.patch(route('api.v1.sets.update', { set: realSetId }), edited).catch((err) => {
                            if (!err.isOffline) markUnsynced(realSetId)
                        })
                    }

                    return realSetId
                })
            })
            /**
             * This is what makes the chain settle rather than reject, and it is
             * load-bearing for more than this set: the next one waits on `creation`
             * through `setCreateChains`, so a create that failed must not stop it
             * from being sent — only from overtaking it. Returning null on every
             * path is what keeps that promise resolvable.
             */
            .catch((err) => {
                if (err.isOffline) {
                    markUnsynced(tempSet.id)

                    return null
                }

                const setIdx = line.sets.findIndex((s) => s.id === tempSet.id)
                if (setIdx !== -1) line.sets.splice(setIdx, 1)
                reportSyncFailure('La série n’a pas pu être ajoutée. Réessaie.')

                return null
            })

        setCreateChains.set(chaineDeLigne, creation)
        pendingIds.track(tempSet.id, creation)
    }

    const removeSet = (setId) => {
        // Clear any pending updates for this set to prevent 404s
        oublierLesRafales(setId)

        // Nothing may queue behind a row that no longer exists, and the entries
        // would otherwise outlive every set the page ever showed.
        NUMERIC_SET_FIELDS.forEach((field) => fieldWrites.forget(`${setId}_${field}`))

        oublierLaSerie(setId)

        // Find the line and set
        for (const line of localWorkout.value.workout_lines) {
            const setIdx = line.sets.findIndex((s) => s.id === setId)
            if (setIdx !== -1) {
                const removedSet = line.sets.splice(setIdx, 1)[0]
                deleteSet(setId).catch((err) => {
                    if (!err.isOffline) {
                        line.sets.splice(setIdx, 0, removedSet)
                        reportSyncFailure('La série n’a pas pu être supprimée. Réessaie.')
                    }
                })
                break
            }
        }
    }

    /**
     * Ce qu'une ligne retiree laisse derriere elle : les rafales en attente de ses
     * series, et sa chaine de creation, indexee sur une chaine que rien ne ramasse.
     */
    const oublierLesEcrituresDeLaLigne = (line) => {
        line.sets?.forEach((set) => oublierLesRafales(set.id))

        setCreateChains.delete(rowKey(line))
    }

    return { addSet, removeSet, oublierLesEcrituresDeLaLigne }
}
