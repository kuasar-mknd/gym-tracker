import { createWriteQueue } from '@/Utils/writeOrdering'
import SyncService from '@/Utils/SyncService'
import { classifySyncError, SYNC_OFFLINE, SYNC_PERMANENT } from '@/Utils/syncErrors'
import { NUMERIC_SET_FIELDS } from '@/composables/useBrouillonsDeSeries'

/**
 * Les ecritures d'une serie : l'ajout, la saisie, la validation, le retrait,
 * et ce que chacune doit a l'ordre des precedentes. La page ne garde que
 * l'identite des rangees, le minuteur de repos et la fusion des props.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   queuedLineIds: Set<string>,
 *   nouvelIdTemporaire: () => string,
 *   newRowKey: () => string,
 *   rowKey: (rangee: object) => string|number,
 *   nextWrite: (cle: string) => number,
 *   isLatestWrite: (cle: string, seq: number) => boolean,
 *   fieldWrites: { queue: (cle: string, envoyer: () => Promise<unknown>) => Promise<unknown>, forget: (cle: string) => void },
 *   lastConfirmed: (set: object, field: string, repli: unknown) => unknown,
 *   rememberConfirmed: (setId: unknown, field: string, valeur: unknown) => void,
 *   writeDraftField: (setId: unknown, field: string, valeur: unknown) => void,
 *   clearDraftField: (setId: unknown, field: string) => void,
 *   oublierLaSerie: (setId: unknown) => void,
 *   markUnsynced: (setId: unknown) => void,
 *   clearUnsynced: (setId: unknown, realId?: unknown) => void,
 *   reportSyncFailure: (message: string) => void,
 *   reportEditFailure: (message: string) => void,
 *   apresValidation: (exerciseRestTime: number|undefined) => void,
 * }} page
 */
export const useSeriesDeLaSeance = ({
    localWorkout,
    pendingIds,
    queuedLineIds,
    nouvelIdTemporaire,
    newRowKey,
    rowKey,
    nextWrite,
    isLatestWrite,
    fieldWrites,
    lastConfirmed,
    rememberConfirmed,
    writeDraftField,
    clearDraftField,
    oublierLaSerie,
    markUnsynced,
    clearUnsynced,
    reportSyncFailure,
    reportEditFailure,
    apresValidation,
}) => {
    /**
     * The same, for completion. Kept apart from `fieldWrites` on purpose:
     * marking a set done and typing into it are independent, and queueing one
     * behind the other would make the tick wait on a debounce it has nothing to do
     * with. Only completion against completion needs ordering.
     *
     * @type {Map<string, Promise>}
     */
    const completionWrites = createWriteQueue()

    /*
     * Les series dont la validation est partie sans avoir encore de reponse.
     *
     * `mergeServerWorkout` reprend la copie du serveur pour toute serie qui n'est
     * pas marquee « non synchronisee » — un marquage reserve aux ecritures de
     * VALEUR refusees. Une validation en vol n'etait donc protegee par rien : le
     * serveur, qui ne l'a pas encore enregistree, renvoie legitimement
     * `is_completed: false`, et la coche disparaissait de l'ecran le temps de
     * l'aller-retour.
     *
     * Un `Set` simple et non reactif : il n'est lu que par la fusion, qui tourne
     * dans un observateur, et le rendre reactif ferait boucler cet observateur sur
     * ses propres ecritures.
     */
    const completionsEnVol = new Set()

    /**
     * The only two ways this screen may talk to the server about a set.
     *
     * Both wait out a creation still in flight, so the URL always carries an id the
     * server issued. When the row has no server-side counterpart they reject with
     * the `isOffline` shape every caller here already understands: keep the value
     * on screen, change nothing, and mark it unsynced rather than send a request
     * that can only be refused.
     */
    const patchSet = (set, payload) =>
        pendingIds.resolve(set.id).then((realId) => {
            if (realId === null) {
                markUnsynced(set.id)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.patch(route('api.v1.sets.update', { set: realId }), payload)
        })

    const deleteSet = (setId) =>
        pendingIds.resolve(setId).then((realId) => {
            if (realId === null) {
                pendingIds.forget(setId)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.delete(route('api.v1.sets.destroy', { set: realId }))
        })

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
     * Flush (execute immediately) all pending debounced updates for a given set.
     * This prevents interleaved PATCH requests from overwriting each other's results.
     */
    /**
     * Sends everything still sitting in the debounce, and says when it has landed.
     *
     * Two moments need this and neither could wait out the timer: leaving the page,
     * and closing the session. SetPolicy refuses writes to a finished workout, so a
     * value typed a second before tapping Terminer used to go out after the workout
     * was closed and come back 403 — reverted on screen, with the page already gone.
     */
    const flushAllPendingUpdates = () => {
        const inFlight = Object.keys(updateTimers).map((key) => {
            const pending = updateTimers[key]

            clearTimeout(pending.timerId)
            delete updateTimers[key]

            return pending.execute?.()
        })

        return Promise.allSettled(inFlight.filter(Boolean))
    }

    /**
     * @returns {Promise} settled once every flushed write for this set has landed.
     */
    const flushPendingUpdates = (setId) => {
        const inFlight = NUMERIC_SET_FIELDS.map((field) => {
            const timerKey = `${setId}_${field}`
            const pending = updateTimers[timerKey]

            if (!pending) return null

            clearTimeout(pending.timerId)
            delete updateTimers[timerKey]

            // Execute the pending update immediately
            return pending.execute?.()
        })

        return Promise.allSettled(inFlight.filter(Boolean))
    }

    const toggleSetCompletion = (set, exerciseRestTime) => {
        const newState = !set.is_completed
        const previousState = set.is_completed

        /**
         * Sequenced and queued, exactly as the value writes are.
         *
         * The protection built in #1319 was written per set and per *field*, and
         * the fields it listed were the four numeric ones. Completion was left
         * out, although nothing stops two of its requests overlapping: the button
         * is only disabled once the session is finished, so validating a set and
         * unvalidating it a moment later sends two PATCH for the same row. Whichever
         * answer landed second was applied — and it could be the older one, which
         * ticked the box back on screen *and* left the server holding that value.
         *
         * `completion:` keys it separately from the numeric fields so that marking a
         * set done still overlaps freely with typing into it; only completion
         * against completion is serialised.
         *
         * La cle et le rang sont pris ICI, avant le moindre `await`, et sur une
         * identite qui ne bouge pas. Les deux garde-fous ci-dessus se laissaient
         * contourner chacun par une faille distincte, trouvees en cherchant la
         * cause de #1503 :
         *
         * `set.id` est REMPLACE en place quand la creation de la serie repond
         * (`tempSet.id = realSetId`). Une validation faite pendant que la creation
         * etait en vol prenait donc la cle `completion:temp-3`, et le geste suivant
         * `completion:12` — deux cles, donc deux sequenceurs et deux files. La
         * reponse tardive de la premiere interrogeait une cle que plus rien
         * n'ecrivait, s'entendait repondre qu'elle etait la plus recente, et
         * recochait une serie que l'utilisateur venait de decocher. `_rowKey` est
         * frappe a la creation de la ligne et ne change jamais ; les series venues
         * du serveur n'en ont pas, mais leur identifiant est deja immuable.
         *
         * Et le rang etait pris APRES le vidage ci-dessous. Cette attente est
         * longue pour le premier appui — elle vide le debounce et attend l'ecriture
         * de valeur — et vide pour le second, que le premier a deja purge. Le
         * second doublait donc le premier et prenait le rang 1 ; le plus ANCIEN
         * devenait « le plus recent », et c'est lui que le serveur entendait en
         * dernier. L'ecran pouvait avoir raison pendant que la base avait tort, ce
         * qui est pire qu'un ecran faux : rien ne le montre.
         */
        const writeKey = `completion:${set._rowKey ?? set.id}`
        const seq = nextWrite(writeKey)

        // ⚡ Perf: Optimistic update — no router.reload
        set.is_completed = newState
        if (newState) {
            apresValidation(exerciseRestTime)
        }

        const send = async () => {
            /**
             * Awaited, not merely started.
             *
             * The pending value writes were flushed and the completion PATCH sent in the
             * same tick, so both were in flight at once against the same row with no
             * ordering between them. Whichever answer came back second was applied over
             * the first, which is how validating a set right after typing into it could
             * put the old weight back on screen. The set's numbers are settled first;
             * only then does it get marked done.
             *
             * Dans le maillon de la file, et non avant elle : attendre dehors
             * laissait le second appui atteindre la file avant le premier, donc
             * partir avant lui. Ici, l'ordre d'entree dans la file est celui des
             * appuis, et le vidage garde sa garantie.
             */
            await flushPendingUpdates(set.id)

            return patchSet(set, { is_completed: newState })
                .then((response) => {
                    // A reply that is no longer the latest word on this set is read
                    // for nothing: applying it would undo the tap that overtook it.
                    if (!isLatestWrite(writeKey, seq)) {
                        return
                    }

                    // Only merge back the fields we sent + metadata to avoid overwriting
                    // concurrent optimistic updates (e.g. weight/reps changes)
                    if (response.data?.data) {
                        set.is_completed = response.data.data.is_completed
                        set.personal_record = response.data.data.personal_record
                        set.updated_at = response.data.data.updated_at
                    }
                })
                .catch((err) => {
                    if (err.isOffline || !isLatestWrite(writeKey, seq)) {
                        return
                    }

                    set.is_completed = previousState
                    reportSyncFailure('La série n’a pas pu être validée. Réessaie.')
                })
        }

        completionsEnVol.add(writeKey)

        return completionWrites.queue(writeKey, send).finally(() => completionsEnVol.delete(writeKey))
    }

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

    // ⚡ Perf: Optimistic addSet — no router.reload
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

    // ⚡ Perf: Optimistic updateSet — no router.reload
    const updateTimers = {}
    const updateSet = (set, field, rawValue) => {
        const value = NUMERIC_SET_FIELDS.includes(field) ? toNumberOrNull(rawValue) : rawValue

        // Not a number and not an empty field: there is nothing to record. Writing
        // it anyway is how NaN used to reach both the row and the payload.
        if (value === undefined) {
            return
        }

        // Skip API calls for temp sets that haven't been created on the server yet
        if (String(set.id).startsWith('temp-')) {
            set[field] = value
            return
        }

        const timerKey = `${set.id}_${field}`
        const rafaleEnCours = updateTimers[timerKey]

        /*
         * La valeur d'avant la RAFALE, pas d'avant la touche.
         *
         * `confirmedValues` n'est alimente que par une reponse ACCEPTEE. Tant que
         * le champ n'a jamais ete enregistre, `lastConfirmed` retombe donc sur son
         * repli — `set[field]`, deja ecrase par la touche precedente. Au second
         * caractere de « 99 », « la valeur d'avant » valait ainsi 9 : ce que
         * l'utilisateur venait de taper, que le serveur n'a jamais entendu.
         *
         * Un refus restaurait alors une valeur qui n'a existe nulle part, et la
         * liaison a sens unique la reecrivait dans le champ. L'utilisateur voyait
         * sa saisie amputee de son dernier caractere, sans rien pour l'expliquer.
         *
         * Le debounce fond les touches d'une meme rafale en une seule ecriture ;
         * la valeur a restaurer si elle est refusee est celle d'avant la premiere
         * d'entre elles. On la garde donc sur la rafale.
         */
        const previousValue = rafaleEnCours ? rafaleEnCours.previousValue : lastConfirmed(set, field, set[field])

        set[field] = value
        if (rafaleEnCours?.timerId) clearTimeout(rafaleEnCours.timerId)

        const seq = nextWrite(timerKey)

        const send = () =>
            patchSet(set, { [field]: value })
                .then((response) => {
                    clearDraftField(set.id, field)

                    // Only merge back the specific field we updated + metadata
                    // to avoid overwriting concurrent optimistic updates
                    if (!response.data?.data) {
                        return
                    }

                    rememberConfirmed(set.id, field, response.data.data[field])

                    // A newer write for this field has gone out since. Its answer is
                    // the one that describes the row; this one is history.
                    if (!isLatestWrite(timerKey, seq)) {
                        return
                    }

                    set[field] = response.data.data[field]
                    set.updated_at = response.data.data.updated_at
                })
                .catch((err) => {
                    const kind = classifySyncError(err)

                    if (kind === SYNC_OFFLINE) {
                        // SyncService owns it now; a draft would be a second copy of
                        // the same pending write.
                        clearDraftField(set.id, field)

                        return
                    }

                    // Superseded by a later edit, which is on screen and has its own
                    // request in flight. Reverting here would undo that instead.
                    if (!isLatestWrite(timerKey, seq)) {
                        return
                    }

                    clearDraftField(set.id, field)
                    set[field] = previousValue

                    // The revert was the only feedback, announced by a haptic pulse:
                    // nothing at all on a desktop, and nothing for anyone who has
                    // haptics off. The value snapped back with no reason given.
                    reportEditFailure(
                        kind === SYNC_PERMANENT
                            ? 'Cette valeur a été refusée. La précédente est rétablie.'
                            : "Impossible d'enregistrer. La valeur précédente est rétablie.",
                    )
                })

        /**
         * Queues this write behind the one before it for the same field.
         *
         * The sequence guard above settles which ANSWER is worth believing, and that
         * alone is not enough: it protects the screen while leaving the database to
         * whichever request the server happened to handle last. Two PATCHes for one
         * field really can overlap — a flush pushes the first one out and the next
         * keystroke starts another — and the older value landing second is written,
         * permanently, over the newer one. The user's last word has to be the last
         * word in the row too.
         *
         * Settled rather than resolved: a refused write must not wedge the field.
         */
        const execute = () => {
            const inOrder = fieldWrites.queue(timerKey, send)

            return inOrder
        }

        writeDraftField(set.id, field, value)

        updateTimers[timerKey] = {
            timerId: setTimeout(() => {
                execute()
                delete updateTimers[timerKey]
            }, 1000),
            execute,
            previousValue,
        }
    }

    // ⚡ Perf: Optimistic removeSet — no router.reload
    const removeSet = (setId) => {
        // Clear any pending updates for this set to prevent 404s
        NUMERIC_SET_FIELDS.forEach((field) => {
            const timerKey = `${setId}_${field}`
            if (updateTimers[timerKey]) {
                clearTimeout(updateTimers[timerKey].timerId)
                delete updateTimers[timerKey]
            }

            // Nothing may queue behind a row that no longer exists, and the entries
            // would otherwise outlive every set the page ever showed.
            fieldWrites.forget(timerKey)
        })

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
     * The duration is no longer parsed out of a string here.
     *
     * It was read from an <input type="time">, which reports an EMPTY value while
     * its segments are incomplete — and the old parser did `val.split(':')` on it
     * regardless, so a NaN was written onto the set, wiped the field mid-typing and
     * reached the database as null. DurationWheel emits whole seconds and nothing
     * else, so there is no string to mis-parse and no incomplete state to guard.
     * The formatting it needs lives with it.
     */

    /**
     * What a numeric field is worth once it leaves the DOM.
     *
     * `e.target.value` is a string, always. Storing it as one made `80` and `'80'`
     * two different values to every `!==` on this page — addSet's post-create diff
     * fired a redundant PATCH on every single set it created — and let a partial
     * entry travel as-is. An empty field is a cleared value, which the column is
     * nullable for; anything unparseable is not a value at all and is dropped.
     */
    /**
     * Une frappe, par opposition a une saisie terminee.
     *
     * Les quatre champs numeriques sont lies a sens unique (`:value="set.weight"`)
     * et n'ecrivaient le modele qu'au `@change`, c'est-a-dire AU BLUR. Tant que le
     * champ n'etait pas quitte, la valeur tapee n'existait que dans le DOM — et le
     * rattrapage qui suit la creation de la serie la manquait, puisqu'il compare le
     * modele a ce qui a ete envoye :
     *
     *     Object.keys(sent).filter((field) => tempSet[field] !== sent[field])
     *
     * C'est le defaut de #1489 : ajouter une serie, taper un poids, et le laisser
     * disparaitre parce qu'on n'avait pas quitte le champ avant que la reponse
     * n'arrive. Reproduit dans `workoutSetEntry.test.js`.
     *
     * La chaine vide est ignoree ici, et elle seule. Un `input[type=number]` rend
     * `''` pour toute saisie incomplete — « 12. » en cours de frappe en est une —
     * et l'ecrire dans le modele ferait reecrire `:value` par Vue, donc effacerait
     * le point sous les doigts de l'utilisateur. Vider reellement un champ reste
     * traite, mais au blur, par `updateSet` via `@change`.
     */
    const saisieEnCours = (set, field, rawValue) => {
        if (rawValue === '') {
            return
        }

        updateSet(set, field, rawValue)
    }

    /**
     * Ce que le blur apporte de neuf, et rien d'autre.
     *
     * `@change` ne doit surtout pas rejouer `updateSet` pour une valeur que
     * `@input` a deja ecrite. Le faire appelait la mise a jour deux fois pour une
     * meme saisie, et le second appel prenait pour valeur de reference celle que le
     * premier venait d'ecrire : un refus du serveur restaurait alors la valeur
     * refusee au lieu de la derniere valeur acceptee — le defaut meme que
     * `workoutOptimisticWrites.test.js` tient depuis #1319.
     *
     * La comparaison au modele plutot qu'un test sur la chaine vide : elle couvre le
     * champ reellement vide, que `saisieEnCours` ecarte, mais aussi tout `change`
     * qui n'aurait pas ete precede d'un `input`.
     */
    const saisieTerminee = (set, field, rawValue) => {
        if (toNumberOrNull(rawValue) === set[field]) {
            return
        }

        updateSet(set, field, rawValue)
    }

    const toNumberOrNull = (value) => {
        if (value === '' || value === null || value === undefined) {
            return null
        }

        const parsed = Number(value)

        return Number.isFinite(parsed) ? parsed : undefined
    }

    /**
     * Ce qu'une ligne retiree laisse derriere elle : les rafales en attente de ses
     * series, et sa chaine de creation, indexee sur une chaine que rien ne ramasse.
     */
    const oublierLesEcrituresDeLaLigne = (line) => {
        line.sets?.forEach((set) => {
            NUMERIC_SET_FIELDS.forEach((field) => {
                const timerKey = `${set.id}_${field}`
                if (updateTimers[timerKey]) {
                    clearTimeout(updateTimers[timerKey].timerId)
                    delete updateTimers[timerKey]
                }
            })
        })

        setCreateChains.delete(rowKey(line))
    }

    return {
        patchSet,
        flushAllPendingUpdates,
        flushPendingUpdates,
        toggleSetCompletion,
        addSet,
        updateSet,
        removeSet,
        saisieEnCours,
        saisieTerminee,
        completionsEnVol,
        oublierLesEcrituresDeLaLigne,
    }
}
