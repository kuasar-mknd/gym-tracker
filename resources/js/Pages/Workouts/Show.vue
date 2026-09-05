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
import GlassButton from '@/Components/UI/GlassButton.vue'
import { useOrdreDeLaSeance } from '@/composables/useOrdreDeLaSeance'
import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
import { useRapportDeSynchronisation } from '@/composables/useRapportDeSynchronisation'
import { useSeriesDeLaSeance } from '@/composables/useSeriesDeLaSeance'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'
import SyncService from '@/Utils/SyncService'
import { PendingIds, isTemporaryId } from '@/Utils/pendingIds'
import Modal from '@/Components/UI/Modal.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import { Head, useForm, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
})

const page = usePage()

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

const removeLine = (lineId) => {
    const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)
    confirmMessage.value = `Supprimer ${line?.exercise?.name || "l'exercice"} ?`

    confirmAction.value = () => {
        oublierLesEcrituresDeLaLigne(line)

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

const {
    unsyncedSetIds,
    clearUnsynced,
    markUnsynced,
    editError,
    reportEditFailure,
    reportSyncFailure,
    handleSyncAuthRequired,
    handleSyncStorageFull,
    handleSyncFailure,
    markQueuedFailuresOnMount,
} = useRapportDeSynchronisation({
    page,
    exercices: () => localExercises.value,
    lignes: () => localWorkout.value.workout_lines,
})

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

const {
    releverLesValeursDuServeur,
    rememberConfirmed,
    lastConfirmed,
    writeDraftField,
    clearDraftField,
    oublierLaSerie,
    rejouerLesBrouillons,
} = useBrouillonsDeSeries()

const {
    ordreEnVol,
    ordreConfirme,
    memoriserLOrdreDesSeries,
    listeDesExercices,
    annonceReorganisation,
    poserLeConteneurDeSeries,
    serieEnDeplacement,
    generationDesSeries,
    deplacerExercice,
    deplacerSerie,
    ecarterLesCommandes,
    peutReordonner,
} = useOrdreDeLaSeance({
    localWorkout,
    isFinished,
    pendingIds,
    nextWrite,
    isLatestWrite,
    fieldWrites,
    reportSyncFailure,
})

const {
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
} = useSeriesDeLaSeance({
    localWorkout,
    pendingIds,
    queuedLineIds,
    nouvelIdTemporaire: () => `temp-${++tempIdCounter}`,
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
    apresValidation: (exerciseRestTime) => {
        if (!autoRestTimer.value) return

        timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
        timerRun.value += 1
        showTimer.value = true
    },
})

// Les tests vident le debounce d'une serie par ici.
defineExpose({ flushPendingUpdates })

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

    rejouerLesBrouillons({
        trouverLaSerie: (setId) => {
            for (const line of localWorkout.value.workout_lines ?? []) {
                const set = line.sets?.find((s) => String(s.id) === String(setId))
                if (set) {
                    return set
                }
            }

            return null
        },
        envoyer: patchSet,
        marquerNonSynchronisee: markUnsynced,
    })
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
                <CarteDExercice
                    v-for="(line, lineIndex) in localWorkout.workout_lines"
                    :key="rowKey(line)"
                    :line="line"
                    :line-index="lineIndex"
                    :is-finished="isFinished"
                    :deplacable="localWorkout.workout_lines.length > 1"
                    :reordonnable="peutReordonner(line)"
                    :serie-en-vol="serieEnDeplacement === line.id"
                    :generation="generationDesSeries.get(line.id) ?? 0"
                    :clef="rowKey"
                    :est-non-synchronisee="(set) => unsyncedSetIds.has(String(set.id))"
                    :poser-le-conteneur="poserLeConteneurDeSeries(line.id)"
                    @deplacer="(nouveau) => deplacerExercice(lineIndex, nouveau)"
                    @retirer="removeLine(line.id)"
                    @ajouter-serie="addSet(line.id)"
                    @pointerdown="ecarterLesCommandes"
                    @toggle="(set) => toggleSetCompletion(set, line.exercise.default_rest_time)"
                    @remove="(set) => removeSet(set.id)"
                    @saisie-en-cours="(set, field, value) => saisieEnCours(set, field, value)"
                    @saisie-terminee="(set, field, value) => saisieTerminee(set, field, value)"
                    @update="(set, field, value) => updateSet(set, field, value)"
                    @deplacer-serie="(index, nouveau) => deplacerSerie(line, index, nouveau)"
                />
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
        <AjoutDExerciceModal
            :show="showAddExercise"
            :exercises="localExercises"
            @close="showAddExercise = false"
            @add="addExercise"
            @created="localExercises.push($event)"
        />

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
