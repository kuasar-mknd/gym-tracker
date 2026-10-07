import { getCurrentInstance, onUnmounted } from 'vue'
import SyncService from '@/Utils/SyncService'
import { creerLesAttentesDeRejeu } from '@/Utils/attentesDeRejeu'
import { NUMERIC_SET_FIELDS } from '@/composables/useBrouillonsDeSeries'
import { raisonDuRefus } from '@/composables/useSaisieDeSerie'

/**
 * Deux valeurs d'un champ mesuré qui disent la même chose : `80`, `80.0` et
 * `'80'` sont un même poids, et le serveur rend des nombres là où un champ de
 * saisie peut tenir du texte.
 */
const memeValeur = (a, b) =>
    a === b ||
    (a !== null && b !== null && a !== undefined && b !== undefined && a !== '' && b !== '' && Number(a) === Number(b))

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
 *   bornesDUneSerie?: Record<string, number>|null,
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
    bornesDUneSerie = null,
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
     * Une valeur recopiée, ramenée entre zéro et le plafond de son champ.
     *
     * La série ajoutée reprend la dernière de la ligne, ou la recommandation du
     * serveur. Une série enregistrée avant les plafonds d'une série
     * (`Set::bornes()`, reçus en props) peut les dépasser : recopiée telle
     * quelle, la création était refusée à chaque essai, et l'exercice ne
     * pouvait plus recevoir de série. Une valeur absente, non numérique ou
     * déjà dans les bornes reste telle quelle ; sans plafonds reçus, rien
     * n'est ramené.
     *
     * @param {string} champ
     * @param {unknown} valeur
     * @returns {unknown}
     */
    const ramenerALaBorne = (champ, valeur) => {
        const plafond = bornesDUneSerie?.[champ]
        const nombre = Number(valeur)

        if (typeof plafond !== 'number' || valeur === null || valeur === '' || !Number.isFinite(nombre)) {
            return valeur
        }

        if (nombre > plafond) return plafond

        return nombre < 0 ? 0 : valeur
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

    /**
     * Les créations de séries parties dans la file hors ligne, qui attendent le
     * vidage pour apprendre leur identifiant. L'écran qui s'en va les oublie ;
     * les écritures, elles, restent en file et partiront sans lui.
     */
    const attentes = creerLesAttentesDeRejeu()

    if (getCurrentInstance()) {
        onUnmounted(() => attentes.oublierTout())
    }

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
        const values = Object.fromEntries(measured.map((field) => [field, ramenerALaBorne(field, prefilled[field])]))

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
        const tentativePrecedente = setCreateChains.get(chaineDeLigne) ?? Promise.resolve()

        /**
         * The server owns the identity, the user owns the values.
         *
         * Assigning the server's copy over the row reverted whatever the user
         * typed while the create was in flight — and typing into a set the
         * instant you add it is the normal way to use this screen. The payload
         * left with the old numbers, so the server's answer necessarily carries
         * them back; taking it wholesale overwrote the new ones on screen and
         * left the database holding values the user had already corrected.
         *
         * Mutating in place also keeps the row identity that v-model and the
         * per-set debounce timers are bound to.
         *
         * Les valeurs, et elles seules : `is_completed` est exclue. La charge
         * envoyee la porte, donc cocher pendant que la creation etait en vol la
         * faisait entrer dans ce diff. Ce PATCH-ci part SANS sequenceur ni
         * file : il courait contre la chaine de completion, qui elle est
         * ordonnee, et rien n'arbitrait entre les deux sinon l'ordre d'arrivee
         * au serveur. Il etait de toute facon redondant : `toggleSetCompletion`
         * est garee sur `pendingIds.resolve(set.id)`, qui se resout sur la
         * creation ; son ecriture ordonnee part donc a l'instant ou la creation
         * retombe, et elle porte deja la validation.
         *
         * Ce qui est comparé à l'écran, c'est ce que le serveur GARDERA, et
         * non ce qui est parti. Ils diffèrent quand le serveur avait déjà fait
         * cette création, sa réponse perdue en route : il reconnaît la clé
         * d'idempotence, rend la série telle qu'il l'avait enregistrée et
         * ignore la charge rejouée, saisie et coche fondues comprises. Le
         * vidage renvoie lui-même ce qu'il a ignoré, par une modification mise
         * en file à la place de la création (`ajusterPar`), et l'annonce dans
         * `created` : un rechargement ne le perd plus (#1960). L'écran ne
         * renvoie donc que ce qui a été tapé pendant que la requête volait.
         * Tant que cette modification attend, la série reste « non
         * enregistrée », et une fusion des props ne reprend pas la copie
         * d'avant.
         *
         * @param {object} created la série telle que le serveur la gardera
         * @param {object|null} sent ce que la création a réellement emporté
         * @param {string|null} ajustement l'entrée de file qui lui porte ce
         *   qu'il a ignoré, s'il y en a une
         */
        const adopter = (created, sent, ajustement = null) => {
            /** Ce que le serveur garde pour ce champ : sa réponse, ou à défaut ce qui est parti. */
            const tenu = (field) => (Object.hasOwn(created, field) ? created[field] : sent?.[field])

            const edited = Object.fromEntries(
                measured
                    .filter((field) => !memeValeur(tempSet[field], tenu(field)))
                    .map((field) => [field, tempSet[field]]),
            )

            const realSetId = created.id

            if (ajustement === null) {
                // It is in the database now, under either id it has worn.
                clearUnsynced(tempSet.id, realSetId)
            } else {
                clearUnsynced(tempSet.id)

                if (SyncService.estEnFile(ajustement)) {
                    markUnsynced(realSetId)

                    // Refusée, la modification laisse la série marquée : le rapport l'a dit.
                    attentes.attendre(ajustement).then((issue) => {
                        if (issue?.data !== undefined) clearUnsynced(realSetId)
                    })
                }
            }

            tempSet.id = realSetId
            tempSet.created_at = created.created_at
            tempSet.updated_at = created.updated_at
            tempSet.personal_record = created.personal_record

            // Typed after the payload left: the server has never seen it.
            if (Object.keys(edited).length > 0) {
                SyncService.patch(route('api.v1.sets.update', { set: realSetId }), edited).catch((err) => {
                    if (!err.isOffline) markUnsynced(realSetId)
                })
            }

            return realSetId
        }

        /**
         * L'adresse qui modifie la série que cette création aura produite : le
         * vidage y renvoie ce que le serveur aura ignoré d'une création rejouée.
         */
        const ajusterPar = (realId) => route('api.v1.sets.update', { set: realId })

        /**
         * Le premier envoi : la série créée, mise en file, ou refusée.
         *
         * C'est sur lui, et non sur la création entière, que la série suivante
         * s'aligne : une création mise en file n'apprend son identifiant qu'au
         * vidage, et la suivante doit pouvoir se ranger derrière elle dans la
         * file sans l'attendre.
         *
         * @type {Promise<{created: object|null, sent: object}|{queueId: string}|null>}
         */
        const tentative = tentativePrecedente
            .then(() => pendingIds.reference(lineId))
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
                            tempSet[field] = ramenerALaBorne(field, line.recommended_values[field] ?? tempSet[field])
                        }
                    }
                }

                const sent = {
                    is_completed: false,
                    ...Object.fromEntries(measured.map((field) => [field, tempSet[field]])),
                }

                const charge = { workout_line_id: realLineId, ...sent }

                /*
                 * L'exercice attend lui-même dans la file : la série s'y range
                 * tout de suite derrière lui, sans tentative, et nomme l'exercice
                 * par son entrée de file. Elle attendait en mémoire que le vidage
                 * annonce l'identifiant de l'exercice, et un rechargement la
                 * perdait ; le vidage créait ensuite un exercice vide (#1962).
                 */
                if (typeof realLineId === 'object') {
                    markUnsynced(tempSet.id)

                    const queueId = SyncService.mettreEnFile({
                        method: 'post',
                        url: route('api.v1.sets.store'),
                        data: charge,
                        ajusterPar,
                    })

                    return queueId === null ? null : { queueId }
                }

                return SyncService.post(route('api.v1.sets.store'), charge, { ajusterPar }).then((response) => ({
                    created: response.data?.data ?? null,
                    sent,
                }))
            })
            /**
             * This is what makes the chain settle rather than reject, and it is
             * load-bearing for more than this set: the next one waits on this
             * attempt through `setCreateChains`, so a create that failed must
             * not stop it from being sent — only from overtaking it.
             */
            .catch((err) => {
                if (err.isOffline) {
                    markUnsynced(tempSet.id)

                    return err.queueId ? { queueId: err.queueId } : null
                }

                const setIdx = line.sets.findIndex((s) => s.id === tempSet.id)
                if (setIdx !== -1) line.sets.splice(setIdx, 1)

                /*
                 * Un refus qui nomme un champ dit pourquoi, et réessayer
                 * renverrait la même valeur : le message du serveur remplace
                 * alors l'invitation à réessayer.
                 */
                const raison = measured.map((field) => raisonDuRefus(err, field)).find((message) => message !== null)
                reportSyncFailure(
                    raison
                        ? `La série n’a pas pu être ajoutée. ${raison}`
                        : 'La série n’a pas pu être ajoutée. Réessaie.',
                )

                return null
            })

        /**
         * La création entière : l'identifiant réel, ou null.
         *
         * Une création partie dans la file se résolvait à null pour toute la vie
         * de la page. La rangée gardait son identifiant provisoire après le
         * vidage : sa saisie, sa coche et sa suppression ne partaient jamais, la
         * fusion des props l'affichait à côté de la copie du serveur, et son
         * badge « non enregistrée » ne se levait plus (#1960). Elle attend
         * désormais le vidage, puis prend l'identifiant que le serveur a émis et
         * renvoie ce qui a été tapé pendant que la requête volait.
         */
        const creation = tentative.then((issue) => {
            if (issue?.queueId) {
                pendingIds.noterEnFile(tempSet.id, issue.queueId)

                /*
                 * Ce qui a été saisi ou coché pendant la tentative n'avait pas
                 * encore d'entrée où se fondre : la rangée telle qu'elle est à
                 * l'écran la rejoint maintenant, et survit avec elle à un
                 * rechargement.
                 */
                SyncService.modifierEnFile(issue.queueId, {
                    is_completed: tempSet.is_completed,
                    ...Object.fromEntries(measured.map((field) => [field, tempSet[field]])),
                })

                return attentes
                    .attendre(issue.queueId)
                    .then((rejeu) =>
                        rejeu?.data?.id === undefined ? null : adopter(rejeu.data, rejeu.envoye, rejeu.ajustement),
                    )
            }

            return issue?.created ? adopter(issue.created, issue.sent) : null
        })

        setCreateChains.set(chaineDeLigne, tentative)
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
