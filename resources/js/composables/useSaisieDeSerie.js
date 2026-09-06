import { classifySyncError, SYNC_OFFLINE, SYNC_PERMANENT } from '@/Utils/syncErrors'
import { NUMERIC_SET_FIELDS } from '@/composables/useBrouillonsDeSeries'

/**
 * La saisie d'une valeur dans une série : la rafale de touches fondue en une
 * écriture après le debounce, son repli si le serveur refuse, le vidage de ce
 * qui attend, et l'oubli des rafales d'une série qui s'en va.
 *
 * @param {{
 *   patchSet: (set: object, payload: object) => Promise<unknown>,
 *   nextWrite: (cle: string) => number,
 *   isLatestWrite: (cle: string, seq: number) => boolean,
 *   fieldWrites: { queue: (cle: string, envoyer: () => Promise<unknown>) => Promise<unknown> },
 *   lastConfirmed: (set: object, field: string, repli: unknown) => unknown,
 *   rememberConfirmed: (setId: unknown, field: string, valeur: unknown) => void,
 *   writeDraftField: (setId: unknown, field: string, valeur: unknown) => void,
 *   clearDraftField: (setId: unknown, field: string) => void,
 *   reportEditFailure: (message: string) => void,
 * }} page
 */
export const useSaisieDeSerie = ({
    patchSet,
    nextWrite,
    isLatestWrite,
    fieldWrites,
    lastConfirmed,
    rememberConfirmed,
    writeDraftField,
    clearDraftField,
    reportEditFailure,
}) => {
    const updateTimers = {}

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

    /** Annule les rafales en attente d'une série : plus rien ne partira pour elle. */
    const oublierLesRafales = (setId) => {
        NUMERIC_SET_FIELDS.forEach((field) => {
            const timerKey = `${setId}_${field}`
            if (updateTimers[timerKey]) {
                clearTimeout(updateTimers[timerKey].timerId)
                delete updateTimers[timerKey]
            }
        })
    }

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

    return {
        flushAllPendingUpdates,
        flushPendingUpdates,
        updateSet,
        saisieEnCours,
        saisieTerminee,
        oublierLesRafales,
    }
}
