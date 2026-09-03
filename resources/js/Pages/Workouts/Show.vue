<script setup>
/**
 * Workout Show Page (Active Workout View)
 *
 * This is the primary component for tracking an active workout session.
 * It manages the state of exercises, sets, and rest timers.
 *
 * Key Features:
 * - Optimistic UI updates for immediate feedback when completing sets.
 * - Background synchronization (`SyncService`) to persist changes to the backend.
 * - Integrated rest timer that automatically starts when a set is marked complete.
 * - Haptic feedback integration for a tactile user experience.
 *
 * @prop {Object} workout - The workout object containing metadata and nested `workout_lines` (which contain `sets`).
 * @prop {Array} exercises - List of all available exercises for adding to the workout.
 * @prop {Array} categories - Distinct list of exercise categories (e.g., Chest, Back, Legs) for filtering.
 * @prop {Array} types - Distinct list of exercise types (e.g., Barbell, Dumbbell, Machine) for filtering.
 */
import { createWriteSequencer, createWriteQueue } from '@/Utils/writeOrdering'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import { useListeReordonnable, useSousListesReordonnables } from '@/composables/useListeReordonnable'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import DurationWheel from '@/Components/Workout/DurationWheel.vue'
import SyncService from '@/Utils/SyncService'
import { classifySyncError, SYNC_OFFLINE, SYNC_PERMANENT } from '@/Utils/syncErrors'
import { PendingIds, isTemporaryId } from '@/Utils/pendingIds'
import Modal from '@/Components/UI/Modal.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import { Head, useForm, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
})

const page = usePage()
import { EXERCISE_CATEGORIES, EXERCISE_TYPES } from '@/Utils/constants'

// ⚡ Perf: Use a mutable reactive ref instead of computed to support optimistic UI updates
const localWorkout = ref(JSON.parse(JSON.stringify(props.workout)))
if (localWorkout.value.workout_lines && !Array.isArray(localWorkout.value.workout_lines)) {
    localWorkout.value.workout_lines = Object.values(localWorkout.value.workout_lines)
}

/**
 * Rebuilds from the server's copy without discarding what it has not heard of.
 *
 * This watch used to assign the incoming props wholesale. Any ordinary Inertia
 * round trip on this page — renaming the session, correcting its start time —
 * therefore threw away every row still being created and every value the server
 * had refused, without a word. The user renamed their workout and the sets they
 * had just added disappeared.
 *
 * The server is authoritative for everything it knows about. It is not
 * authoritative about rows it has never been told of, nor about values it
 * rejected while the user kept theirs on screen.
 */
/**
 * Un deplacement d'exercice parti, dont le serveur n'a pas encore rendu compte.
 *
 * `mergeServerWorkout` reconstruit la liste depuis la copie du serveur, donc
 * dans SON ordre. Un rafraichissement de props pendant qu'un deplacement est en
 * vol — un renommage de seance, une correction d'heure — remettrait les
 * exercices comme ils etaient, sous les yeux de qui vient de les bouger.
 *
 * C'est le pendant, pour les lignes, de ce que `unsyncedSetIds` fait pour les
 * series.
 */
const ordreEnVol = ref(0)

/**
 * L'ordre que le serveur a accepte en dernier.
 *
 * Le repli d'un echec ne peut pas partir d'un instantane pris a l'appel : avec
 * deux deplacements enchaines, il rendrait l'etat d'avant le PREMIER et
 * effacerait le second sans un mot.
 */
const ordreConfirme = ref([])

/** Le meme, par exercice, pour ses series. */
const ordreDesSeriesConfirme = new Map()

const mergeServerWorkout = (server, local) => {
    const merged = JSON.parse(JSON.stringify(server))

    if (merged.workout_lines && !Array.isArray(merged.workout_lines)) {
        merged.workout_lines = Object.values(merged.workout_lines)
    }

    if (!Array.isArray(merged.workout_lines)) {
        merged.workout_lines = []
    }

    const localLines = Array.isArray(local?.workout_lines) ? local.workout_lines : []

    merged.workout_lines.forEach((line) => {
        if (!Array.isArray(line.sets)) line.sets = []

        const localLine = localLines.find((candidate) => candidate.id === line.id)

        if (!localLine) return

        /*
         * L'identite de la rangee survit au rafraichissement.
         *
         * Le serveur ne connait pas `_rowKey` : il est frappe ici, a la
         * creation. La copie JSON ci-dessus le perdait donc a chaque
         * rafraichissement de props — un renommage de seance, une correction
         * d'heure, un « enregistrer comme modele ».
         *
         * Deux consequences, et la seconde ne se voit pas. `rowKey()` sert de
         * `:key` au `v-for` : sans `_rowKey` il retombe sur l'id, donc la cle
         * de CHAQUE rangee change et Vue detruit puis reconstruit des lignes
         * qui n'ont pas bouge — etat de glissement perdu, champs recrees, focus
         * qui saute en pleine frappe. Et l'ordonnancement des ecritures est
         * indexe sur cette meme identite : deux appuis encadrant le
         * rafraichissement reprenaient deux cles, et les deux garde-fous
         * sautaient ensemble.
         */
        if (localLine._rowKey) line._rowKey = localLine._rowKey

        const localSets = Array.isArray(localLine.sets) ? localLine.sets : []

        // Still being created, or waiting in the offline queue.
        localSets.filter((set) => isTemporaryId(set.id)).forEach((set) => line.sets.push(set))

        // Marked unsynced means the server's copy is the stale one; taking it
        // would quietly undo an edit the user can see on screen.
        line.sets.forEach((set, index) => {
            const localSet = localSets.find((candidate) => candidate.id === set.id)

            if (!localSet) return

            if (unsyncedSetIds.value.has(String(set.id))) {
                line.sets[index] = localSet

                return
            }

            if (localSet._rowKey) set._rowKey = localSet._rowKey

            // La validation est partie, le serveur ne l'a pas encore : sa copie
            // est la perimee des deux.
            if (completionsEnVol.has(`completion:${rowKey(localSet)}`)) {
                set.is_completed = localSet.is_completed
            }
        })
    })

    // Whole exercises the server has never heard of.
    localLines.filter((line) => isTemporaryId(line.id)).forEach((line) => merged.workout_lines.push(line))

    // L'ordre local est le plus recent des deux tant que le deplacement n'a pas
    // atterri : le reprendre du serveur annulerait le geste a l'ecran.
    if (ordreEnVol.value > 0) {
        const rang = new Map(localLines.map((line, index) => [String(line.id), index]))

        merged.workout_lines.sort((a, b) => (rang.get(String(a.id)) ?? Infinity) - (rang.get(String(b.id)) ?? Infinity))
    }

    return merged
}

// Sync with Inertia props when they change (e.g. after redirect-based actions)
watch(
    () => props.workout,
    (newVal) => {
        releverLesValeursDuServeur(newVal)
        localWorkout.value = mergeServerWorkout(newVal, localWorkout.value)

        if (ordreEnVol.value === 0) {
            ordreConfirme.value = localWorkout.value.workout_lines.map((ligne) => ligne.id)
        }

        memoriserLOrdreDesSeries()
    },
)

/**
 * A closed session is a record, not a workspace.
 *
 * The page had no idea a workout could be finished and rendered the full live
 * editor regardless. SetPolicy refuses every write to a closed session, so each
 * control answered 403 — and only the two that revert visibly said anything at
 * all. Adding an exercise, adding a set and deleting one simply did nothing.
 */
const isFinished = computed(() => Boolean(localWorkout.value.ended_at))

const showTimer = ref(false)
const timerDuration = ref(90)

/**
 * Le reglage est tenu localement pour que l'interrupteur bouge tout de suite,
 * puis ecrit. La page reste sur place : basculer un reglage ne doit pas sortir
 * de la seance en cours.
 */
const autoRestTimer = ref(usePage().props.auth.user.auto_rest_timer !== false)

/**
 * Le repos, demande explicitement.
 *
 * Sans lui, couper le demarrage automatique fermait une porte a sens unique :
 * plus rien n'ouvrait le minuteur, et l'interrupteur qui le rallume vit DANS le
 * minuteur. Le reglage etait donc irreversible depuis l'interface.
 */
const openRestTimer = () => {
    timerDuration.value = usePage().props.auth.user.default_rest_time || 90
    timerRun.value += 1
    showTimer.value = true
}

/*
 * Reordonner les exercices, au doigt, pendant la seance.
 *
 * Les cartes ne se replient PAS pendant le geste. C'est delibere : replier
 * raccourcissait la page de 400 px sous le doigt, et il fallait ensuite
 * rattraper le defilement, distinguer la tape du glissement, et devancer le
 * moment ou la bibliotheque photographie la carte. Trois mecanismes pour un
 * confort — et chacun ramenait un defaut.
 *
 * Une carte pleine se traine tres bien tant que le defilement automatique
 * fonctionne aux bords, ce que la configuration ci-dessous assure.
 */
const listeDesExercices = ref(null)

/** Ce qu'un lecteur d'ecran entend apres un deplacement. */
const annonceReorganisation = ref('')

/*
 * La bibliotheque mute CE tableau elle-meme : elle est donnee-d'abord, ce qui
 * laisse Vue proprietaire du DOM. C'est ce qui evite d'avoir a defaire ses
 * deplacements, et avec eux toute une classe de defauts.
 */
const lignesReordonnables = computed({
    get: () => localWorkout.value.workout_lines,
    set: (valeur) => {
        localWorkout.value.workout_lines = valeur
    },
})

const { rafraichir: rafraichirLeDeplacement } = useListeReordonnable(listeDesExercices, {
    valeurs: lignesReordonnables,
    handle: '[data-poignee-exercice]',
    estActif: () => !isFinished.value && localWorkout.value.workout_lines.length > 1,
    auDebut: () => triggerHaptic('tap'),
    aLaFin: () => persisterLOrdre(),
})

const memoriserLOrdreDesSeries = () => {
    localWorkout.value.workout_lines.forEach((ligne) => {
        if (!ordreDesSeriesConfirme.has(ligne.id) && Array.isArray(ligne.sets)) {
            ordreDesSeriesConfirme.set(
                ligne.id,
                ligne.sets.map((serie) => serie.id),
            )
        }
    })
}

onMounted(() => {
    ordreConfirme.value = localWorkout.value.workout_lines.map((ligne) => ligne.id)
    memoriserLOrdreDesSeries()
    void rafraichirLesSeries()
    rafraichirLeDeplacement()
})

watch(
    () => [isFinished.value, localWorkout.value.workout_lines.length],
    () => rafraichirLeDeplacement(),
)

/*
 * Les series se reordonnent aussi, mais elles vivent dans UNE liste par
 * exercice : il faut donc lier chaque conteneur separement, au fur et a mesure
 * qu'il apparait.
 */
const conteneursDeSeries = new Map()

const poserLeConteneurDeSeries = (lineId) => (element) => {
    if (element === null) {
        conteneursDeSeries.delete(lineId)
        oublierLesSeries(lineId)

        return
    }

    conteneursDeSeries.set(lineId, element)
}

/**
 * L'exercice dont une serie est en vol. Sa rangee cesse alors d'ecouter le
 * glissement lateral : les deux gestes partent du meme endroit et avanceraient
 * ensemble.
 */
const serieEnDeplacement = ref(null)

const generationDesSeries = ref(new Map())

/**
 * La bibliotheque deplace le nœud elle-meme ; Vue, restee sur l'ancien
 * arrangement, ecrit ensuite les numeros dans les mauvaises rangees. Changer la
 * generation les reconstruit dans l'ordre du tableau.
 */
const reconstruireLesSeries = (lineId) => {
    const generations = new Map(generationDesSeries.value)

    generations.set(lineId, (generations.get(lineId) ?? 0) + 1)
    generationDesSeries.value = generations
}

const { rafraichir: rafraichirLesSeries, oublier: oublierLesSeries } = useSousListesReordonnables(
    () =>
        localWorkout.value.workout_lines.map((ligne) => ({
            cle: ligne.id,
            element: conteneursDeSeries.get(ligne.id) ?? null,
            valeurs: computed({
                get: () => ligne.sets,
                set: (valeur) => {
                    ligne.sets = valeur
                },
            }),
        })),
    {
        handle: '[data-poignee-serie]',
        estActif: () => !isFinished.value,
        appuiLong: true,
        auDebut: (lineId) => {
            serieEnDeplacement.value = lineId
            triggerHaptic('tap')
        },
        aLaFin: (lineId) => {
            serieEnDeplacement.value = null
            reconstruireLesSeries(lineId)
            persisterLOrdreDesSeries(lineId)
        },
    },
)

watch(
    () => localWorkout.value.workout_lines.map((ligne) => `${ligne.id}:${ligne.sets?.length ?? 0}`).join(),
    () => rafraichirLesSeries(),
    { flush: 'post' },
)

/**
 * L'ordre part en entier, pas par echange.
 *
 * Deux lignes d'une meme seance peuvent partager un rang : l'index n'est pas
 * unique, le client peut fournir `order`, et deux creations concurrentes lisent
 * le meme maximum. Echanger deux rangs egaux n'ecrirait rien ; renumeroter
 * depuis la liste soumise les departage.
 */
const deplacerExercice = (ancien, nouveau) => {
    const lignes = localWorkout.value.workout_lines

    // Au doigt, la bibliotheque ne rend jamais un rang hors liste ; au clavier,
    // le premier exercice recoit « monter » comme les autres.
    if (nouveau < 0 || nouveau >= lignes.length || nouveau === ancien) {
        return
    }

    lignes.splice(nouveau, 0, ...lignes.splice(ancien, 1))

    annonceReorganisation.value = `${lignes[nouveau].exercise.name} déplacé en position ${nouveau + 1} sur ${lignes.length}`

    persisterLOrdre()
}

/**
 * Ecrire l'ordre courant, sans y toucher.
 *
 * Au doigt, la bibliotheque a DEJA reordonne le tableau — muter ici
 * appliquerait le deplacement deux fois. Aux fleches et au clavier, c'est
 * `deplacerExercice` qui mute avant d'appeler.
 */
const persisterLOrdre = () => {
    const lignes = localWorkout.value.workout_lines

    ordreEnVol.value += 1

    const seq = nextWrite('line-order')

    const envoyer = () => {
        /*
         * La charge est une permutation COMPLETE, donc un deplacement plus
         * recent remplace exactement celui-ci : l'abandonner n'est pas une
         * economie, c'est la seule facon de ne pas faire retenir au serveur un
         * ordre perime.
         */
        if (!isLatestWrite('line-order', seq)) {
            return Promise.resolve()
        }

        return Promise.all(lignes.map((ligne) => pendingIds.resolve(ligne.id)))
            .then((realLineIds) => {
                if (realLineIds.some((id) => id === null)) {
                    throw Object.assign(new Error('exercice sans identifiant serveur'), { isOffline: false })
                }

                return SyncService.patch(route('api.v1.workouts.line-order', { workout: localWorkout.value.id }), {
                    lines: realLineIds,
                }).then(() => {
                    ordreConfirme.value = realLineIds
                })
            })
            .catch((err) => {
                if (err.isOffline) {
                    return
                }

                const parId = new Map(localWorkout.value.workout_lines.map((ligne) => [ligne.id, ligne]))

                localWorkout.value.workout_lines = ordreConfirme.value
                    .map((id) => parId.get(id))
                    .filter((ligne) => ligne !== undefined)

                reportSyncFailure('L’ordre des exercices n’a pas pu être enregistré. Réessaie.')
            })
    }

    fieldWrites.queue('line-order', envoyer).finally(() => {
        ordreEnVol.value = Math.max(0, ordreEnVol.value - 1)
    })
}

/**
 * Ecrire l'ordre des series d'un exercice.
 *
 * La bibliotheque a deja reordonne le tableau : ce chemin ne fait qu'ecrire, et
 * la charge est la permutation COMPLETE — les series anciennes partagent un
 * rang, donc un echange n'ecrirait rien.
 */
/**
 * Une commande qui prend le doigt pour elle ne demarre pas un deplacement : le
 * geste s'arrete a elle. Une seule regle plutot qu'un attribut sur chaque champ
 * — la rangee en compte jusqu'a six, et un ajout futur serait oublie.
 *
 * La pastille du numero fait exception. C'est un bouton pour porter les fleches
 * du clavier, rien de plus : elle ne repond a aucune tape, et l'ecarter du
 * geste rendait la rangee insaisissable a l'endroit le plus naturel.
 */
const ecarterLesCommandes = (evenement) => {
    const commande = evenement.target.closest('button, input, select, textarea, a')

    if (commande !== null && !commande.hasAttribute('data-poignee-clavier')) {
        evenement.stopPropagation()
    }
}

/** Une seule serie ne se reordonne pas, et une seance close ne bouge plus. */
const peutReordonner = (ligne) => !isFinished.value && ligne.sets.length > 1

const deplacerSerie = (ligne, ancien, nouveau) => {
    if (nouveau < 0 || nouveau >= ligne.sets.length || nouveau === ancien) {
        return
    }

    ligne.sets.splice(nouveau, 0, ...ligne.sets.splice(ancien, 1))

    persisterLOrdreDesSeries(ligne.id)
}

const persisterLOrdreDesSeries = (lineId) => {
    const ligne = localWorkout.value.workout_lines.find((candidate) => candidate.id === lineId)

    if (ligne === undefined) {
        return
    }

    const avantEcriture = ordreDesSeriesConfirme.get(lineId) ?? ligne.sets.map((serie) => serie.id)
    const cle = `set-order:${lineId}`
    const seq = nextWrite(cle)

    const envoyer = () => {
        if (!isLatestWrite(cle, seq)) {
            return Promise.resolve()
        }

        return Promise.all([pendingIds.resolve(lineId), ...ligne.sets.map((serie) => pendingIds.resolve(serie.id))])
            .then(([realLineId, ...realSetIds]) => {
                if (realLineId === null || realSetIds.some((id) => id === null)) {
                    throw Object.assign(new Error('série sans identifiant serveur'), { isOffline: false })
                }

                return SyncService.patch(route('api.v1.workout-lines.set-order', { workoutLine: realLineId }), {
                    sets: realSetIds,
                }).then(() => {
                    ordreDesSeriesConfirme.set(lineId, realSetIds)
                })
            })
            .catch((err) => {
                if (err.isOffline) {
                    return
                }

                const parId = new Map(ligne.sets.map((serie) => [serie.id, serie]))

                ligne.sets = avantEcriture.map((id) => parId.get(id)).filter((serie) => serie !== undefined)

                reportSyncFailure('L’ordre des séries n’a pas pu être enregistré. Réessaie.')
            })
    }

    fieldWrites.queue(cle, envoyer)
}

const setAutoRestTimer = (valeur) => {
    autoRestTimer.value = valeur

    router.patch(
        route('profile.rest-timer.update'),
        { auto_rest_timer: valeur },
        { preserveScroll: true, preserveState: true },
    )
}

/**
 * Counts rest periods, so each one gets a fresh timer.
 *
 * The timer only reset itself while it was NOT running, and completing a set
 * while it was already counting down neither remounted it nor restarted it. The
 * second set of a superset was therefore given whatever was left of the first
 * one's rest — the shorter the gap between sets, the shorter the rest, which is
 * precisely backwards.
 */
const timerRun = ref(0)

let tempIdCounter = 0

/**
 * A row's identity for Vue, which must not change while the row is on screen.
 *
 * Ids do change here: an optimistic row wears `temp-4` until the server answers
 * and is then given the real one. Keying a v-for on that made the key change
 * under a row the user was typing into, and Vue answers a changed key by
 * destroying the node and building a new one — the input, its half-typed value
 * and its focus with it.
 *
 * So optimistic rows carry a key issued once at creation, and rows that came
 * from the server fall back to their id, which for them never changes. The two
 * cannot collide: one is a string with a prefix, the other a number.
 */
let rowKeyCounter = 0
const newRowKey = () => `row-${++rowKeyCounter}`
const rowKey = (row) => row._rowKey ?? row.id

/**
 * Placeholder ids never leave this component. Every mutation that names a line
 * or a set asks here for the id to actually send, and waits if the creation is
 * still in flight — see Utils/pendingIds for what sending `temp-3` cost.
 */
const pendingIds = new PendingIds()

/**
 * Placeholders whose create is sitting in the offline queue rather than in
 * flight. Anything added on top of one has to wait for the drain, and say so
 * meanwhile.
 */
const queuedLineIds = new Set()
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

/**
 * Says out loud that the server refused something.
 *
 * Every failure path on this screen used to do the same two things — put the
 * optimistic row back the way it was, and buzz. On a phone that is a vibration
 * with no words; on a desktop it is nothing at all. So a 500 was indis-
 * tinguable from a mis-tap, and an afternoon went by with the server rejecting
 * every write while the screen just kept quietly undoing them.
 *
 * The layout already renders a toast from `flash.error` and clears it after
 * eight seconds, so this borrows that rather than inventing a second channel.
 * Offline is deliberately excluded: the queue handles it, the value stays on
 * screen, and there is nothing for the user to do about it.
 */
const reportSyncFailure = (message) => {
    const flash = page.props.flash ?? (page.props.flash = {})

    flash.error = message
    triggerHaptic('error')
}

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
 * The set fields this screen edits. Every one of them is a number in the
 * database, so a value arriving from an input — always a string — is normalised
 * before it goes anywhere near the row or the payload.
 */
const NUMERIC_SET_FIELDS = ['weight', 'reps', 'distance_km', 'duration_seconds']

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
    if (newState && autoRestTimer.value) {
        timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
        timerRun.value += 1
        showTimer.value = true
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

const savingTemplate = ref(false)
const saveAsTemplate = () => {
    savingTemplate.value = true
    router.post(
        route('templates.save-from-workout', { workout: localWorkout.value.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => (savingTemplate.value = false),
        },
    )
}

const showFinishModal = ref(false)
const finishWorkout = () => {
    showFinishModal.value = true
}
const confirmFinishWorkout = async () => {
    /**
     * Awaited, not merely started. Closing the session revokes the right to
     * write to its sets, so the last value typed has to be accepted before the
     * workout is finished — otherwise it arrives at a closed session, is refused
     * 403, and is reverted on a page that has already navigated away.
     */
    await flushAllPendingUpdates()

    router.patch(
        route('workouts.update', { workout: localWorkout.value.id }),
        { is_finished: true },
        {
            onStart: () => {
                showFinishModal.value = false
            },
            onSuccess: () => {
                triggerHaptic('success')
            },
        },
    )
}

const showAddExercise = ref(false)
const searchQuery = ref(localStorage.getItem('gymtracker_add_exercise_search') || '')
watch(searchQuery, (newVal) => {
    localStorage.setItem('gymtracker_add_exercise_search', newVal)
})
const showCreateForm = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))
const showConfirmModal = ref(false)
const confirmAction = ref(null)
const confirmMessage = ref('')

const executeConfirmAction = () => {
    if (typeof confirmAction.value === 'function') {
        confirmAction.value()
    }
}
const showSettingsModal = ref(false)

const settingsForm = useForm({
    name: localWorkout.value.name,
    started_at: formatToLocalISO(localWorkout.value.started_at),
    notes: localWorkout.value.notes || '',
})

const updateSettings = () => {
    settingsForm
        .transform((data) => ({ ...data, started_at: formatToUTC(data.started_at) }))
        .patch(route('workouts.update', { workout: localWorkout.value.id }), {
            preserveScroll: true,
            onSuccess: () => {
                showSettingsModal.value = false
            },
        })
}

// ⚡ Perf: addExercise via API call + optimistic UI instead of Inertia redirect
const addExercise = (exerciseId) => {
    const exercise = localExercises.value.find((e) => e.id === exerciseId)
    if (!exercise) return

    // Optimistic: add line immediately
    const tempLine = {
        id: `temp-${++tempIdCounter}`,
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
    searchQuery.value = ''

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

const createAndAddExercise = async () => {
    createExerciseForm.processing = true
    createExerciseForm.clearErrors()
    try {
        const response = await fetch(route('exercises.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: createExerciseForm.name,
                type: createExerciseForm.type,
                category: createExerciseForm.category,
            }),
        })
        if (response.ok) {
            const data = await response.json()
            const exercise = data.exercise
            localExercises.value.push(exercise)
            addExercise(exercise.id)
            showCreateForm.value = false

            return
        }

        // Anything other than 2xx used to fall through here and do nothing at
        // all: the modal stayed open with no message, so the user just kept
        // tapping. Surface the server's validation errors instead.
        if (response.status === 422) {
            const { errors = {} } = await response.json()

            Object.entries(errors).forEach(([field, messages]) => {
                createExerciseForm.setError(field, [].concat(messages)[0])
            })
        } else {
            createExerciseForm.setError('name', 'La création a échoué. Réessaie dans un instant.')
        }

        triggerHaptic('error')
    } catch (e) {
        console.error(e)
        createExerciseForm.setError('name', 'Connexion impossible. Vérifie ta connexion réseau.')
        triggerHaptic('error')
    } finally {
        createExerciseForm.processing = false
    }
}

const quickCreate = () => {
    createExerciseForm.name = searchQuery.value
    showCreateForm.value = true
}
const closeModal = () => {
    showAddExercise.value = false
    showCreateForm.value = false
    searchQuery.value = ''
}

const removeLine = (lineId) => {
    const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)
    confirmMessage.value = `Supprimer ${line?.exercise?.name || "l'exercice"} ?`

    confirmAction.value = () => {
        // Clear any pending updates for sets in this line
        line.sets?.forEach((set) => {
            NUMERIC_SET_FIELDS.forEach((field) => {
                const timerKey = `${set.id}_${field}`
                if (updateTimers[timerKey]) {
                    clearTimeout(updateTimers[timerKey].timerId)
                    delete updateTimers[timerKey]
                }
            })
        })

        /*
         * La chaine de creation de cette ligne n'a plus rien a serialiser.
         *
         * Elle etait indexee sur l'objet ligne, dans une `WeakMap` qui la
         * ramassait toute seule ; la cle est desormais une chaine, donc
         * l'entree survivrait a la ligne pour toute la duree de la page.
         */
        setCreateChains.delete(rowKey(line))

        // ⚡ Perf: Optimistic removal
        const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === lineId)
        const removedLine = idx !== -1 ? localWorkout.value.workout_lines.splice(idx, 1)[0] : null
        showConfirmModal.value = false

        // Waits out a creation still in flight rather than deleting `temp-1`,
        // which 404s and leaves the row on the server after the user removed it.
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
    showConfirmModal.value = true
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
        id: `temp-${++tempIdCounter}`,
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

/**
 * Sets whose value is on screen but not in the database.
 *
 * Drafts written while offline are replayed on mount. When the server refuses
 * one, the value stays — throwing away an edit the user made earlier, with no
 * feedback, is worse than showing it unsaved — so the row is marked instead.
 * A refusal is recorded in the draft itself so the next mount stops retrying
 * something a 4xx says will never be accepted.
 */
const unsyncedSetIds = ref(new Set())

/**
 * Clears the marker once the row genuinely reaches the database. Without this
 * the warning was add-only: a set that synced on the next drain kept telling
 * the user it had not been saved, for as long as the page stayed open.
 */
const clearUnsynced = (...setIds) => {
    setIds.forEach((setId) => unsyncedSetIds.value.delete(String(setId)))
}

const markUnsynced = (setId) => {
    unsyncedSetIds.value.add(String(setId))
}

/**
 * SyncService keeps the mutations a server refused rather than dropping them,
 * and announces each one. Most of them are set updates, so this is where they
 * become visible instead of sitting in localStorage unread.
 */
/**
 * La file hors-ligne a trouvé porte close (session ou jeton expirés) ou un
 * stockage plein. Ni l'un ni l'autre n'est un refus de l'écriture, mais
 * l'utilisateur doit savoir quoi faire : se reconnecter, ou ne pas recharger.
 */
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

const handleSyncFailure = (event) => {
    const url = event.detail?.url ?? ''
    const setId = /\/sets\/(\d+)/.exec(url)?.[1]

    if (setId) {
        markUnsynced(setId)

        return
    }

    /**
     * Only an id-bearing URL could be attached to a row, so a refused CREATE —
     * `POST /api/v1/sets`, `POST /api/v1/workout-lines` — matched nothing and
     * the user was told nothing at all. The write is recorded in SyncService's
     * failed bucket either way; this is the part they can see.
     *
     * There is no row to mark, because the thing that failed is the row itself,
     * so the message has to carry the identification instead — and name the
     * exercise. "An item of the session" leaves someone scrolling their own
     * workout trying to work out which set never made it.
     */
    if (/\/(sets|workout-lines)(\?|$)/.test(url)) {
        reportEditFailure(describeFailedCreate(url, event.detail?.data))
    }
}

/** The request body, whether it was queued as an object or already serialised. */
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
    localExercises.value.find((exercise) => String(exercise.id) === String(exerciseId))?.name

/**
 * Names what the server refused, falling back to the old wording only when the
 * payload cannot be tied to anything on screen — a set whose line has since been
 * removed, say. Being vague is better than being wrong about which set it was.
 */
const describeFailedCreate = (url, data) => {
    const payload = payloadOf(data)
    const generic = "Un élément de la séance n'a pas pu être enregistré."

    if (/\/workout-lines(\?|$)/.test(url)) {
        const name = exerciseNamed(payload.exercise_id)

        return name ? `« ${name} » n'a pas pu être ajouté à la séance.` : "Un exercice n'a pas pu être ajouté."
    }

    const line = localWorkout.value.workout_lines?.find(
        (candidate) => String(candidate.id) === String(payload.workout_line_id),
    )

    if (!line) {
        return generic
    }

    const position = (line.sets?.length ?? 0) || 1

    return `La série ${position} de « ${line.exercise?.name ?? 'cet exercice'} » n'a pas pu être enregistrée.`
}

/**
 * A rejected edit reverts on screen, which is the right behaviour while the user
 * is looking at the field — but it needs to say why, in something you can read.
 */
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

/**
 * Announces what was refused while the page was away — once.
 *
 * The failed bucket is written on every refusal and, until now, emptied by
 * nobody: `clearFailedRequests()` had no caller anywhere in the app. So a single
 * create the server turned down went on announcing itself on every single visit
 * to the session, for ever, with no way to acknowledge it. That is not a warning
 * any more, it is furniture, and the user reported it as exactly that.
 *
 * The payload goes through too, so the message can name what failed instead of
 * saying "an item of the session".
 */
const markQueuedFailuresOnMount = () => {
    const failures = SyncService.failedRequests()

    if (failures.length === 0) {
        return
    }

    failures.forEach((failure) => handleSyncFailure({ detail: { url: failure.url, data: failure.data } }))

    SyncService.clearFailedRequests()
}

/**
 * Counts the writes issued per set and field, so a reply can be checked against
 * the state of the world rather than trusted because it arrived.
 *
 * Two PATCHes for one field are ordinary here — the debounce is flushed by
 * validating a set, and the next keystroke starts another one — and nothing
 * makes them come home in the order they left. `set[field] = response…[field]`
 * applied whichever landed last, so an older reply carrying the older number
 * overwrote what the user had just typed. That is the value "revenant tout
 * seul" the report describes, and the request it belongs to had succeeded.
 */
const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()

/**
 * The write currently in flight for each set and field, so the next one can
 * queue behind it rather than overlap it. See `execute` in updateSet.
 *
 * @type {Map<string, Promise>}
 */
const fieldWrites = createWriteQueue()

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
 * The value the server last confirmed for a field, which is the only value a
 * rollback may restore.
 *
 * `previousValue` used to be read off the set at call time. Type B then C inside
 * the debounce window and the second call captures B — a value that never
 * reached the database — so a refusal of C "restored" B and the row ended up
 * showing something the server had never agreed to.
 */
const confirmedValues = new Map()
const confirmedKey = (setId, field) => `${setId}_${field}`

/**
 * Ce que le serveur nous a dit, releve a chaque fois qu'il nous le dit.
 *
 * `confirmedValues` n'etait alimente que par une reponse ACCEPTEE. Tant qu'un
 * champ n'avait jamais ete enregistre depuis cette page, `lastConfirmed`
 * retombait donc sur son repli — la valeur a l'ecran, deja optimiste — et un
 * refus restaurait quelque chose que le serveur n'avait jamais eu.
 *
 * #1540 a ferme le cas de la rafale, en gardant la valeur d'avant la premiere
 * touche. Mais des qu'une salve est partie son minuteur est oublie, et la
 * salve suivante retombait sur l'ecran. Deux corrections coup sur coup, toutes
 * deux refusees, laissaient la premiere des deux a l'ecran.
 *
 * La charge utile du serveur EST ce que le serveur detient : la relever ici
 * donne toujours une valeur a restaurer. Les series encore provisoires n'en
 * ont pas — le serveur ne les connait pas.
 */
const releverLesValeursDuServeur = (workout) => {
    // Le serveur envoie parfois les lignes en objet plutot qu'en tableau, et la
    // fusion s'en accommode deja ; ce releve doit en faire autant.
    const lignes = workout?.workout_lines
    const enTableau = Array.isArray(lignes) ? lignes : Object.values(lignes ?? {})

    enTableau.forEach((line) =>
        (Array.isArray(line?.sets) ? line.sets : []).forEach((set) => {
            if (set === null || isTemporaryId(set.id)) {
                return
            }

            NUMERIC_SET_FIELDS.forEach((field) => {
                if (set[field] !== undefined) {
                    rememberConfirmed(set.id, field, set[field])
                }
            })
        }),
    )
}

const rememberConfirmed = (setId, field, value) => {
    confirmedValues.set(confirmedKey(setId, field), value)
}

const lastConfirmed = (set, field, fallback) => {
    const key = confirmedKey(set.id, field)

    return confirmedValues.has(key) ? confirmedValues.get(key) : fallback
}

/**
 * Keeps one draft per set holding only the fields still in flight.
 *
 * The draft was `JSON.stringify(set)` under a single key, removed outright the
 * moment ANY field came back accepted. Correcting a weight and then the reps
 * within the same second meant the weight's success deleted the draft that was
 * holding the reps, whose PATCH had not left yet — close the app in that window
 * and the entry is gone. Only the field that was actually confirmed is dropped
 * now, and the key disappears when nothing is left to protect.
 */
const draftKey = (setId) => `draft_set_${setId}`

const readDraft = (setId) => {
    try {
        return JSON.parse(localStorage.getItem(draftKey(setId)) || '{}')
    } catch {
        return {}
    }
}

const writeDraftField = (setId, field, value) => {
    localStorage.setItem(draftKey(setId), JSON.stringify({ ...readDraft(setId), [field]: value }))
}

const clearDraftField = (setId, field) => {
    const { [field]: _dropped, ...rest } = readDraft(setId)

    if (Object.keys(rest).filter((key) => key !== 'syncRejected').length === 0) {
        localStorage.removeItem(draftKey(setId))

        return
    }

    localStorage.setItem(draftKey(setId), JSON.stringify(rest))
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
        confirmedValues.delete(timerKey)
    })

    // The row is going away; its draft must not outlive it and be replayed
    // against an id that no longer exists on the next mount.
    localStorage.removeItem(draftKey(setId))

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

const createExerciseForm = useForm({ name: '', type: 'strength', category: 'Pectoraux' })

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

const filteredExercises = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()
    return q ? localExercises.value.filter((e) => e.name.toLowerCase().includes(q)) : localExercises.value
})

const handleFabAddExercise = () => {
    showAddExercise.value = true
}

onMounted(() => {
    // Le premier relevé : la séance telle qu'elle est arrivée. Les suivants se
    // font dans l'observateur des props.
    releverLesValeursDuServeur(props.workout)

    window.addEventListener('open-add-exercise', handleFabAddExercise)
    window.addEventListener('sync:failed', handleSyncFailure)
    window.addEventListener('sync:auth-required', handleSyncAuthRequired)
    window.addEventListener('sync:storage-full', handleSyncStorageFull)
    markQueuedFailuresOnMount()

    // Restore set drafts if any exist and haven't synced
    const keysToRemove = []
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i)
        if (key && key.startsWith('draft_set_')) {
            const setId = key.replace('draft_set_', '')
            try {
                const draftData = JSON.parse(localStorage.getItem(key))
                localWorkout.value.workout_lines?.forEach((line) => {
                    const set = line.sets?.find((s) => String(s.id) === String(setId))
                    if (set) {
                        const payload = {}
                        if (draftData.weight !== undefined) {
                            set.weight = draftData.weight
                            payload.weight = draftData.weight
                        }
                        if (draftData.reps !== undefined) {
                            set.reps = draftData.reps
                            payload.reps = draftData.reps
                        }
                        if (draftData.distance_km !== undefined) {
                            set.distance_km = draftData.distance_km
                            payload.distance_km = draftData.distance_km
                        }
                        if (draftData.duration_seconds !== undefined) {
                            set.duration_seconds = draftData.duration_seconds
                            payload.duration_seconds = draftData.duration_seconds
                        }

                        // Already refused once. Keep the value visible and marked,
                        // but stop asking: a 4xx does not become a 2xx.
                        if (draftData.syncRejected) {
                            markUnsynced(set.id)

                            return
                        }

                        patchSet(set, payload)
                            .then(() => {
                                localStorage.removeItem(key)
                            })
                            .catch((err) => {
                                const kind = classifySyncError(err)

                                if (kind === SYNC_OFFLINE) {
                                    // SyncService queued it; the draft would be a
                                    // second copy of the same pending write.
                                    localStorage.removeItem(key)

                                    return
                                }

                                if (kind === SYNC_PERMANENT) {
                                    localStorage.setItem(key, JSON.stringify({ ...draftData, syncRejected: true }))
                                }

                                // Transient failures keep the draft untouched so the
                                // next mount tries again.
                                markUnsynced(set.id)
                            })
                    }
                })
            } catch (e) {
                keysToRemove.push(key)
            }
        }
    }
    keysToRemove.forEach((k) => localStorage.removeItem(k))
})

onUnmounted(() => {
    window.removeEventListener('open-add-exercise', handleFabAddExercise)
    window.removeEventListener('sync:failed', handleSyncFailure)
    window.removeEventListener('sync:auth-required', handleSyncAuthRequired)
    window.removeEventListener('sync:storage-full', handleSyncStorageFull)

    // One per create still waiting on the offline queue; the page may well be
    // left before the drain ever comes.
    replayListeners.forEach((listener) => window.removeEventListener('sync:replayed', listener))
    replayListeners.clear()

    /**
     * Sends whatever is still sitting in the debounce.
     *
     * A value typed a fraction of a second before leaving the page is an edit
     * the user made. It used to be left to fire on its own a second later, into
     * a component that no longer exists: if the server refused it, the revert
     * and the message landed on refs nobody was rendering, so the edit was lost
     * without a word. Flushing here sends it while there is still something to
     * report to — and SyncService records a refusal durably either way.
     */
    flushAllPendingUpdates()

    if (editErrorTimer) {
        clearTimeout(editErrorTimer)
    }
})
</script>

<template>
    <Head :title="localWorkout.name || 'Séance'" />
    <AuthenticatedLayout :page-title="localWorkout.name" :show-back="true" back-route="workouts.index">
        <!-- Fixed rather than in the flow: the set being edited can be anywhere
             down a long session, and a message that scrolls out of view is the
             same as no message. -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="-translate-y-2 opacity-0"
        >
            <div
                v-if="editError"
                role="alert"
                dusk="set-edit-error"
                class="border-accent-danger/30 bg-accent-danger/95 text-text-on-accent fixed inset-x-3 top-20 z-50 rounded-2xl border px-4 py-3 text-sm font-bold shadow-lg backdrop-blur-md"
            >
                {{ editError }}
            </div>
        </Transition>

        <template #header-actions>
            <button
                v-press
                @click="showSettingsModal = true"
                dusk="workout-settings-button"
                :class="[
                    'text-text-muted flex size-11 shrink-0 items-center justify-center rounded-xl',
                    'border-surface-card bg-surface-card/60 border transition-all',
                    ' ',
                ]"
                aria-label="Paramètres de la séance"
            >
                <span class="material-symbols-outlined" aria-hidden="true">settings</span>
            </button>
        </template>

        <div class="pb-main-safe">
            <div ref="listeDesExercices" class="space-y-4" dusk="exercise-list">
                <GlassCard
                    v-if="localWorkout.workout_lines.length === 0"
                    class="flex flex-col items-center justify-center p-12 text-center"
                >
                    <h3 class="font-display text-text-main mb-4 text-2xl font-black uppercase italic">Séance vide</h3>
                    <GlassButton
                        v-if="!isFinished"
                        variant="primary"
                        @click="showAddExercise = true"
                        dusk="add-first-exercise"
                        >Ajouter un exercice</GlassButton
                    >
                    <p v-else class="text-text-muted text-sm font-bold">Cette séance est terminée.</p>
                </GlassCard>

                <!-- Same reasoning as the set rows below: a line's id changes from
                 placeholder to real one when its create lands, and re-keying on
                 it rebuilt the whole exercise card — every set input inside it
                 included — at the exact moment the user was filling in the first
                 set of the exercise they had just added. -->
                <GlassCard
                    v-for="(line, lineIndex) in localWorkout.workout_lines"
                    :key="rowKey(line)"
                    :dusk="`exercise-card-${lineIndex}`"
                    :data-line-id="line.id"
                    :dusk-id="`exercise-line-${line.id}`"
                    data-exercice
                    class="carte-portable"
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <!--
                          text-text-main is near-black and has no dark variant of
                          its own, so in dark mode the exercise name was rendered
                          at 2.20:1 against the page and its category at 1.71:1 —
                          both far under the 4.5:1 that ordinary text needs, and
                          under even the 3:1 allowed for large text. The name of
                          the exercise you are working on was effectively
                          invisible.
                        -->
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                {{ line.exercise.name }}
                            </h3>
                            <p class="text-text-muted text-xs font-bold uppercase">
                                {{ line.exercise.category }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <!--
                          Une poignee, et non la carte entiere : les rangees de
                          series sont deja sensibles au glissement lateral, et
                          laisser la bibliotheque ecouter toute la carte les
                          rendrait inutilisables au doigt.
                        -->
                            <button
                                v-if="!isFinished && localWorkout.workout_lines.length > 1"
                                type="button"
                                data-poignee-exercice
                                class="text-text-muted focus-visible:ring-accent-primary min-h-touch min-w-touch inline-flex cursor-grab touch-none items-center justify-center rounded-lg transition-colors select-none [-webkit-touch-callout:none] focus-visible:ring-2 focus-visible:outline-none active:cursor-grabbing"
                                :dusk="`reorder-line-${lineIndex}`"
                                :aria-label="`Déplacer ${line.exercise.name}`"
                                @keydown.up.prevent="deplacerExercice(lineIndex, lineIndex - 1)"
                                @keydown.down.prevent="deplacerExercice(lineIndex, lineIndex + 1)"
                            >
                                <span class="material-symbols-outlined text-lg" aria-hidden="true">drag_indicator</span>
                            </button>

                            <GlassIconButton
                                v-press="{ haptic: 'warning' }"
                                icon="delete"
                                label="Supprimer l'exercice"
                                ton="danger"
                                compact
                                :dusk="`remove-line-${lineIndex}`"
                                @click="removeLine(line.id)"
                            />
                        </div>
                    </div>

                    <div :ref="poserLeConteneurDeSeries(line.id)" class="space-y-2">
                        <!--
                      Keyed on something that never changes for the life of the
                      row. Folding the index in made the key change for every row
                      below a deletion, so Vue destroyed and rebuilt them all:
                      swipe state reset, inputs re-created, and a field being
                      edited losing focus mid-keystroke.

                      The set's id has the same defect and outlived that fix: an
                      optimistic row wears a placeholder until the server answers,
                      and `tempSet.id = realSetId` then changes the key, so Vue
                      tears the row down and builds a new one — swipe state and
                      inputs included — at the moment the user is most likely to
                      be filling it in.

                      Demonstrated, and no more than that: the guard in
                      workoutSetEntry.test.js fails without this, showing the node
                      really is replaced. It was written while chasing a lost
                      duration entry and did NOT fix it, so nothing here should be
                      read as a diagnosis of that.
                    -->
                        <SwipeableRow
                            v-for="(set, index) in line.sets"
                            :key="`${rowKey(set)}:${generationDesSeries.get(line.id) ?? 0}`"
                            :disabled="serieEnDeplacement === line.id"
                        >
                            <!-- The row was swipeable with no action behind it: dragging
                             it snapped it open onto an empty background and left it
                             there. Same delete the row's own button calls, reached
                             the way Workouts/Index and ExerciseCard already do it. -->
                            <template #action-right>
                                <button
                                    type="button"
                                    @click="removeSet(set.id)"
                                    :dusk="`swipe-remove-set-${lineIndex}-${index}`"
                                    :aria-label="`Supprimer la série ${index + 1}`"
                                    class="bg-accent-danger text-text-on-accent flex h-full w-full items-center justify-center"
                                >
                                    <span class="flex flex-col items-center" aria-hidden="true">
                                        <span class="material-symbols-outlined text-2xl" aria-hidden="true"
                                            >delete</span
                                        >
                                        <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                                    </span>
                                </button>
                            </template>

                            <!--
                              La rangee ENTIERE est la poignee. Une poignee
                              dediee a ete essayee deux fois : l'icone prenait
                              une place qu'on n'a pas, et le numero seul se
                              ratait une fois sur deux. Les zones cliquables
                              s'en retirent une a une, ci-dessous.
                            -->
                            <div
                                :data-poignee-serie="peutReordonner(line) ? '' : undefined"
                                @pointerdown="ecarterLesCommandes"
                                class="border-surface-card bg-surface-card/80 carte-portable flex items-center gap-2 rounded-2xl border p-3 shadow-sm"
                                :class="{ 'opacity-50': set.is_completed }"
                            >
                                <button
                                    v-press
                                    @click="toggleSetCompletion(set, line.exercise.default_rest_time)"
                                    :disabled="isFinished"
                                    :dusk="`complete-set-${lineIndex}-${index}`"
                                    class="group relative flex size-11 shrink-0 items-center justify-center rounded-xl border-2 transition-all"
                                    :class="
                                        set.is_completed
                                            ? 'bg-accent-state text-text-main'
                                            : 'bg-surface-sunken text-text-muted'
                                    "
                                    :aria-label="set.is_completed ? 'Annuler la série' : 'Valider la série'"
                                >
                                    <svg
                                        class="h-6 w-6"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>
                                    <!-- PR Trophy Badge -->
                                    <div
                                        v-if="set.personal_record"
                                        class="bg-accent-warning text-text-on-accent absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full shadow-sm"
                                        :dusk="`pr-trophy-${lineIndex}-${index}`"
                                    >
                                        <span class="material-symbols-outlined text-[12px] font-bold" aria-hidden="true"
                                            >stars</span
                                        >
                                    </div>
                                </button>
                                <!-- Le numero porte le deplacement au CLAVIER. Le
                                     doigt, lui, saisit la rangee entiere. -->
                                <component
                                    :is="peutReordonner(line) ? 'button' : 'div'"
                                    :type="peutReordonner(line) ? 'button' : undefined"
                                    :data-poignee-clavier="peutReordonner(line) ? '' : undefined"
                                    :dusk="`reorder-set-${lineIndex}-${index}`"
                                    :aria-label="peutReordonner(line) ? `Déplacer la série ${index + 1}` : undefined"
                                    class="text-text-muted bg-surface-sunken focus-visible:ring-accent-primary relative flex h-11 w-6 shrink-0 items-center justify-center rounded-lg text-sm font-black select-none focus-visible:ring-2 focus-visible:outline-none"
                                    @keydown.up.prevent="deplacerSerie(line, index, index - 1)"
                                    @keydown.down.prevent="deplacerSerie(line, index, index + 1)"
                                >
                                    {{ index + 1 }}

                                    <!-- The value on screen is not the value in the
                                     database. Said out loud rather than left to a
                                     colour, and not with a title attribute, which
                                     a touch device never shows. -->
                                    <span
                                        v-if="unsyncedSetIds.has(String(set.id))"
                                        class="bg-accent-warning text-text-on-accent absolute -top-1 -right-1 flex size-3.5 items-center justify-center rounded-full"
                                        :dusk="`set-unsynced-${lineIndex}-${index}`"
                                        role="img"
                                        :aria-label="`Série ${index + 1} non enregistrée`"
                                    >
                                        <span class="material-symbols-outlined text-[10px]" aria-hidden="true"
                                            >cloud_off</span
                                        >
                                    </span>
                                </component>

                                <template v-if="line.exercise.type === 'strength'">
                                    <input
                                        type="number"
                                        inputmode="decimal"
                                        :value="set.weight"
                                        @focus="$event.target.select()"
                                        @input="(e) => saisieEnCours(set, 'weight', e.target.value)"
                                        @change="(e) => saisieTerminee(set, 'weight', e.target.value)"
                                        :disabled="isFinished"
                                        :dusk="`weight-input-${lineIndex}-${index}`"
                                        :aria-label="`Poids en kg, série ${index + 1}, ${line.exercise.name}`"
                                        class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                                    />
                                    <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true"
                                        >kg</span
                                    >
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        :value="set.reps"
                                        @focus="$event.target.select()"
                                        @input="(e) => saisieEnCours(set, 'reps', e.target.value)"
                                        @change="(e) => saisieTerminee(set, 'reps', e.target.value)"
                                        :disabled="isFinished"
                                        :dusk="`reps-input-${lineIndex}-${index}`"
                                        :aria-label="`Répétitions, série ${index + 1}, ${line.exercise.name}`"
                                        class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                                    />
                                    <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true"
                                        >réps</span
                                    >
                                </template>

                                <template v-else-if="line.exercise.type === 'cardio'">
                                    <input
                                        type="number"
                                        step="0.1"
                                        inputmode="decimal"
                                        :value="set.distance_km"
                                        @focus="$event.target.select()"
                                        @input="(e) => saisieEnCours(set, 'distance_km', e.target.value)"
                                        @change="(e) => saisieTerminee(set, 'distance_km', e.target.value)"
                                        :disabled="isFinished"
                                        :dusk="`distance-input-${lineIndex}-${index}`"
                                        :aria-label="`Distance en km, série ${index + 1}, ${line.exercise.name}`"
                                        class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                                    />
                                    <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true"
                                        >km</span
                                    >
                                    <DurationWheel
                                        :model-value="set.duration_seconds"
                                        @update:model-value="(seconds) => updateSet(set, 'duration_seconds', seconds)"
                                        :disabled="isFinished"
                                        :fill="false"
                                        :dusk="`duration-input-${lineIndex}-${index}`"
                                        :label="`Durée, série ${index + 1}, ${line.exercise.name}`"
                                    />
                                </template>

                                <template v-else-if="line.exercise.type === 'timed'">
                                    <DurationWheel
                                        :model-value="set.duration_seconds"
                                        @update:model-value="(seconds) => updateSet(set, 'duration_seconds', seconds)"
                                        :disabled="isFinished"
                                        :dusk="`duration-input-${lineIndex}-${index}`"
                                        :label="`Durée, série ${index + 1}, ${line.exercise.name}`"
                                    />
                                </template>

                                <button
                                    v-if="!isFinished"
                                    v-press="{ haptic: 'warning' }"
                                    @click="removeSet(set.id)"
                                    :dusk="`remove-set-${lineIndex}-${index}`"
                                    :class="[
                                        'hover:text-accent-danger-deep text-text-muted relative ml-auto',
                                        'before:absolute before:-inset-2.5 before:content-[\'\']',
                                        // Redundant on a phone, where the row swipes.
                                        // Kept from sm up, where there is no swipe at
                                        // all: SwipeableRow listens for touch events
                                        // only, so a mouse has no other way to delete.
                                        'hidden sm:block',
                                    ]"
                                    aria-label="Supprimer la série"
                                >
                                    <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                                </button>
                            </div>
                        </SwipeableRow>
                    </div>

                    <button
                        v-if="!isFinished"
                        v-press
                        @click="addSet(line.id)"
                        :dusk="`add-set-${lineIndex}`"
                        class="text-text-muted hover:border-accent-state border-border mt-4 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed py-3 text-sm font-bold uppercase transition-all"
                    >
                        Ajouter une série
                    </button>
                </GlassCard>
            </div>

            <p class="sr-only" aria-live="polite">{{ annonceReorganisation }}</p>

            <div v-if="localWorkout.workout_lines.length > 0 && !isFinished" class="mt-8 space-y-3 px-1">
                <!--
                    Pas de variante : la carte pleine. Ajouter un exercice est
                    l'action courante de cette page ; « Modele », en dessous, est
                    occasionnelle et prend le contour. Elles etaient inversees —
                    et invisiblement, puisque ni `secondary` ni `solid`
                    n'existaient dans l'objet de classes.
                -->
                <GlassButton @click="showAddExercise = true" class="w-full" dusk="add-exercise-existing"
                    >Ajouter un exercice</GlassButton
                >
                <GlassButton variant="secondary" @click="openRestTimer" class="w-full" dusk="open-rest-timer"
                    >Démarrer un repos</GlassButton
                >
                <div class="grid grid-cols-2 gap-3">
                    <GlassButton variant="secondary" @click="saveAsTemplate" :loading="savingTemplate" class="w-full"
                        >Modèle</GlassButton
                    >
                    <GlassButton
                        variant="primary"
                        @click="finishWorkout"
                        class="w-full"
                        id="finish-workout-mobile"
                        dusk="finish-workout-mobile"
                        >Terminer</GlassButton
                    >
                </div>
            </div>
        </div>

        <!-- Modals -->
        <Modal :show="showAddExercise" @close="closeModal" max-width="lg" aria-labelledby="add-exercise-title">
            <div class="p-6">
                <h2
                    id="add-exercise-title"
                    class="font-display text-text-main mb-6 text-2xl font-black uppercase italic"
                >
                    Ajouter un exercice
                </h2>
                <div v-if="!showCreateForm">
                    <div class="pb-4">
                        <GlassInput
                            id="search-workout-exercise"
                            v-model="searchQuery"
                            type="search"
                            size="lg"
                            label="Rechercher un exercice"
                            hide-label
                            placeholder="Rechercher..."
                        />
                    </div>
                    <div class="max-h-[60vh] space-y-3 overflow-y-auto">
                        <button
                            v-if="filteredExercises.length === 0 && searchQuery"
                            type="button"
                            @click="quickCreate"
                            dusk="quick-create-exercise"
                            class="border-border hover:border-accent-state focus-visible:ring-accent-state flex w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed p-8 text-center transition-all focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span class="text-text-muted mb-2 block text-sm italic"
                                >Aucun résultat pour "{{ searchQuery }}"</span
                            >
                            <span class="text-accent-state-deep font-bold tracking-wider uppercase"
                                >Créer "{{ searchQuery }}"</span
                            >
                        </button>

                        <!-- button, not div: adding an exercise is the primary action of this
                             screen and was unreachable by keyboard and screen readers.
                             Spans rather than h4/p — flow content is invalid inside a button
                             and produced a phantom heading level in the outline. -->
                        <button
                            v-for="exercise in filteredExercises"
                            :key="exercise.id"
                            type="button"
                            @click="addExercise(exercise.id)"
                            :dusk="`select-exercise-${exercise.id}`"
                            class="glass-panel-light hover:border-accent-primary/50 focus-visible:ring-accent-primary block w-full cursor-pointer rounded-2xl p-4 text-left transition-all focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span class="text-text-main block font-bold">{{ exercise.name }}</span>
                            <span class="text-text-muted block text-xs uppercase">{{ exercise.category }}</span>
                        </button>
                    </div>
                </div>

                <!-- Create Form -->
                <div v-else class="space-y-6">
                    <div class="flex items-center gap-4">
                        <GlassIconButton icon="arrow_back" label="Retour" @click="showCreateForm = false" />
                        <h3 class="font-display text-text-main text-xl font-black uppercase italic">Nouvel Exercice</h3>
                    </div>

                    <form @submit.prevent="createAndAddExercise" class="space-y-4">
                        <GlassInput
                            v-model="createExerciseForm.name"
                            label="Nom"
                            dusk="new-exercise-name"
                            :error="createExerciseForm.errors.name"
                            required
                        />

                        <div class="grid grid-cols-2 gap-4">
                            <GlassSelect
                                v-model="createExerciseForm.type"
                                label="Type"
                                :options="EXERCISE_TYPES"
                                dusk="new-exercise-type"
                            />
                            <GlassSelect
                                v-model="createExerciseForm.category"
                                label="Catégorie"
                                :options="EXERCISE_CATEGORIES.map((c) => ({ value: c, label: c }))"
                                empty-label="— Aucune —"
                                dusk="new-exercise-category"
                            />
                        </div>

                        <GlassButton
                            type="submit"
                            variant="primary"
                            class="w-full"
                            :loading="createExerciseForm.processing"
                            dusk="submit-new-exercise"
                        >
                            Créer et Ajouter
                        </GlassButton>
                    </form>
                </div>
            </div>
        </Modal>

        <WorkoutSettingsModal
            :show="showSettingsModal"
            :form="settingsForm"
            @close="showSettingsModal = false"
            @submit="updateSettings"
        />

        <WorkoutFinishModal :show="showFinishModal" @close="showFinishModal = false" @confirm="confirmFinishWorkout" />

        <Modal
            :show="showConfirmModal"
            @close="showConfirmModal = false"
            max-width="sm"
            aria-labelledby="confirm-title"
        >
            <div class="p-6 text-center">
                <h3 id="confirm-title" class="text-text-main mb-6 text-xl font-bold">{{ confirmMessage }}</h3>
                <div class="flex gap-3">
                    <GlassButton variant="secondary" @click="showConfirmModal = false" class="flex-1"
                        >Annuler</GlassButton
                    >
                    <GlassButton
                        variant="danger"
                        @click="executeConfirmAction"
                        class="flex-1"
                        dusk="confirm-delete-button"
                        >Supprimer</GlassButton
                    >
                </div>
            </div>
        </Modal>

        <RestTimer
            v-if="showTimer"
            :key="timerRun"
            :duration="timerDuration"
            :auto-rest-timer="autoRestTimer"
            @finished="showTimer = false"
            @close="showTimer = false"
            @update:auto-rest-timer="setAutoRestTimer"
            dusk="rest-timer"
        />
    </AuthenticatedLayout>
</template>
