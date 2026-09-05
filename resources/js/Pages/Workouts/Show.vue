<script setup>
/**
 * La seance en cours : ce que la page garde apres son eclatement — la copie
 * locale de la seance, sa fusion avec les props, et le cablage des composables
 * qui font le reste.
 */
import { createWriteSequencer, createWriteQueue } from '@/Utils/writeOrdering'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { useOrdreDeLaSeance } from '@/composables/useOrdreDeLaSeance'
import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
import { useRapportDeSynchronisation } from '@/composables/useRapportDeSynchronisation'
import { useSeriesDeLaSeance } from '@/composables/useSeriesDeLaSeance'
import { useLignesDeLaSeance } from '@/composables/useLignesDeLaSeance'
import { useReglagesDeLaSeance } from '@/composables/useReglagesDeLaSeance'
import { useIdentiteDesRangees } from '@/composables/useIdentiteDesRangees'
import { useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'
import { fusionnerLaSeance } from '@/Utils/fusionDeSeance'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import { Head, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

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

// Sync with Inertia props when they change (e.g. after redirect-based actions)
watch(
    () => props.workout,
    (newVal) => {
        releverLesValeursDuServeur(newVal)
        localWorkout.value = fusionnerLaSeance(newVal, localWorkout.value, {
            estNonSynchronisee: (set) => unsyncedSetIds.value.has(String(set.id)),
            validationEnVol: (set) => completionsEnVol.has(`completion:${rowKey(set)}`),
            ordreLocalPrime: ordreEnVol.value > 0,
        })

        if (ordreEnVol.value === 0) {
            ordreConfirme.value = localWorkout.value.workout_lines.map((ligne) => ligne.id)
        }

        memoriserLOrdreDesSeries()
    },
)

/** Une seance close est un compte rendu, pas un espace de travail. */
const isFinished = computed(() => Boolean(localWorkout.value.ended_at))

const { showTimer, timerDuration, autoRestTimer, timerRun, openRestTimer, setAutoRestTimer, apresValidation } =
    useMinuteurDeRepos()

const { nouvelIdTemporaire, newRowKey, rowKey, pendingIds, queuedLineIds } = useIdentiteDesRangees()
const showAddExercise = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))
const { unsyncedSetIds, clearUnsynced, markUnsynced, editError, reportEditFailure, reportSyncFailure } =
    useRapportDeSynchronisation({
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
})

const {
    savingTemplate,
    saveAsTemplate,
    showFinishModal,
    finishWorkout,
    confirmFinishWorkout,
    showSettingsModal,
    settingsForm,
    updateSettings,
} = useReglagesDeLaSeance({ localWorkout, viderLesEcritures: () => flushAllPendingUpdates() })

const { addExercise, removeLine, retraitDemande, titreDuRetrait, annulerLeRetrait, confirmerLeRetrait } =
    useLignesDeLaSeance({
        localWorkout,
        pendingIds,
        queuedLineIds,
        nouvelIdTemporaire,
        newRowKey,
        localExercises,
        showAddExercise,
        oublierLesEcrituresDeLaLigne,
        reportSyncFailure,
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

    // Une valeur tapee juste avant de partir est une saisie : elle part maintenant.
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

        <ConfirmDialog
            :ouvert="retraitDemande"
            :titre="titreDuRetrait"
            @confirmer="confirmerLeRetrait"
            @annuler="annulerLeRetrait"
        />

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
