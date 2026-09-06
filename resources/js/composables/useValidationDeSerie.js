import { createWriteQueue } from '@/Utils/writeOrdering'

/**
 * La validation d'une série : la coche posée sur-le-champ, l'écriture qui part
 * derrière la saisie en attente et derrière la validation précédente de la même
 * série, et le repos lancé quand elle est cochée.
 *
 * @param {{
 *   patchSet: (set: object, payload: object) => Promise<unknown>,
 *   nextWrite: (cle: string) => number,
 *   isLatestWrite: (cle: string, seq: number) => boolean,
 *   flushPendingUpdates: (setId: unknown) => Promise<unknown>,
 *   reportSyncFailure: (message: string) => void,
 *   apresValidation: (exerciseRestTime: number|undefined) => void,
 * }} page
 */
export const useValidationDeSerie = ({
    patchSet,
    nextWrite,
    isLatestWrite,
    flushPendingUpdates,
    reportSyncFailure,
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

    return { completionsEnVol, toggleSetCompletion }
}
