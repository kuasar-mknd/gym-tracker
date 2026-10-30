<script setup>
/**
 * La seance en cours : ce que la page garde apres son eclatement — la copie
 * locale de la seance, sa fusion avec les props, et le cablage des composables
 * qui font le reste.
 */
import { createWriteSequencer, createWriteQueue } from '@/Utils/writeOrdering'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { useOrdreDeLaSeance } from '@/composables/useOrdreDeLaSeance'
import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
import { useRapportDeSynchronisation } from '@/composables/useRapportDeSynchronisation'
import { useSeriesDeLaSeance } from '@/composables/useSeriesDeLaSeance'
import { useLignesDeLaSeance } from '@/composables/useLignesDeLaSeance'
import { useReglagesDeLaSeance } from '@/composables/useReglagesDeLaSeance'
import { useIdentiteDesRangees } from '@/composables/useIdentiteDesRangees'
import { useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'
import { useSortieAudio } from '@/composables/useSortieAudio'
import { useRechargementApresLeVidage } from '@/composables/useRechargementApresLeVidage'
import { useSeanceEtSaFile } from '@/composables/useSeanceEtSaFile'
import { useCopieDeLaSeance } from '@/composables/useCopieDeLaSeance'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { ID_DE_L_ETAT_DE_LA_SEANCE, useSeanceEnAttente } from '@/composables/useSeanceEnAttente'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'
import { fusionnerLaSeance } from '@/Utils/fusionDeSeance'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import SeriesHorsDeLaCopie from '@/Components/Workout/SeriesHorsDeLaCopie.vue'
import EtatDeLaSeanceEnAttente from '@/Components/Workout/EtatDeLaSeanceEnAttente.vue'
import ErreurDeSaisie from '@/Components/Workout/ErreurDeSaisie.vue'
import { Head, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRaccourcisDeLaSeance } from '@/composables/useRaccourcisDeLaSeance'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
    // Les plafonds d'une série (`Set::bornes()`), sous lesquels une série
    // ajoutée ramène ce qu'elle recopie.
    bornesDUneSerie: { type: Object, default: null },
})

const page = usePage()

// Ce que le serveur détient de chaque série, les brouillons, et les écritures qu'il n'a pas encore confirmées.
const brouillons = useBrouillonsDeSeries()
const { releverLesValeursDuServeur, rejouerLesBrouillons, valeursDeRepli } = brouillons

// La copie locale, modifiable pour l'affichage optimiste : la séance du serveur ou de l'historique, la file par-dessus.
const { localWorkout, seanceDeDepart } = useSeanceEtSaFile({
    seance: props.workout,
    exercices: props.exercises,
    page,
    valeursDeRepli,
})

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

const sortieAudio = useSortieAudio()
const { showTimer, timerDuration, autoRestTimer, timerRun, openRestTimer, setAutoRestTimer, apresValidation } =
    useMinuteurDeRepos({ sortieAudio })
// Sur une copie hors ligne, le réglage du repos ne part pas (#2048) ; le minuteur, lui, tourne.
const { surUneCopie, raisonDUneEcriture } = useCopieHorsLigne()

const { nouvelIdTemporaire, newRowKey, rowKey, pendingIds, queuedLineIds } = useIdentiteDesRangees()
const showAddExercise = ref(false)
// Démarrée sans réseau (#2049) : suivie jusqu'au rejeu de sa création, sans rechargement après le vidage, puis adoptée.
const { seanceProvisoire, etatDeLaSeance, seanceEnLectureSeule } = useSeanceEnAttente({
    seance: props.workout,
    adresse: page.url,
    pendingIds,
    occupee: () => showTimer.value || showAddExercise.value || retraitDemande.value || ordreEnVol.value > 0,
    valeursDeRepli,
})
/** Close, ou démarrée sans réseau puis refusée : rien ne s'y écrit plus. */
const lectureSeule = computed(() => isFinished.value || seanceEnLectureSeule.value)
const bibliotheque = (exercices) => [...(exercices || [])].filter((e) => e && e.id)
const localExercises = ref(bibliotheque(props.exercises))
// Suit le serveur : rechargée depuis sa copie sans remontage (#2048), la séance gardait la bibliothèque de la copie.
watch(
    () => props.exercises,
    (exercices) => (localExercises.value = bibliotheque(exercices)),
)
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
    isFinished: lectureSeule,
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
    ...brouillons,
    markUnsynced,
    clearUnsynced,
    reportSyncFailure,
    reportEditFailure,
    apresValidation,
    bornesDUneSerie: props.bornesDUneSerie,
})

useRaccourcisDeLaSeance({ localWorkout, isFinished: lectureSeule, addSet })
useRechargementApresLeVidage({ actif: !seanceProvisoire })

// La copie hors ligne de la séance, quand tout est enregistré (#2048).
useCopieDeLaSeance({
    localWorkout,
    seanceDeDepart,
    seance: () => props.workout,
    page,
    valeursDeRepli,
    unsyncedSetIds,
    ordreEnVol,
})

const {
    savingTemplate,
    saveAsTemplate,
    showFinishModal,
    ecrituresEnAttente,
    clotureEnCours,
    finishWorkout,
    annulerLaCloture,
    confirmFinishWorkout,
    showSettingsModal,
    settingsForm,
    updateSettings,
} = useReglagesDeLaSeance({ localWorkout, pendingIds, viderLesEcritures: () => flushAllPendingUpdates() })

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

const handleFabAddExercise = () => (showAddExercise.value = !seanceEnLectureSeule.value)

onMounted(() => {
    // Le premier relevé : la séance telle qu'elle est arrivée, du serveur ou de
    // l'historique. Les suivants se font dans l'observateur des props.
    releverLesValeursDuServeur(seanceDeDepart)

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
    <AuthenticatedLayout
        :page-title="localWorkout.name || 'Séance'"
        :show-back="true"
        back-route="workouts.index"
        largeur="moyenne"
    >
        <ErreurDeSaisie :message="editError" />

        <template #header-actions>
            <button
                v-press
                @click="showSettingsModal = true"
                :disabled="seanceProvisoire"
                :aria-describedby="seanceProvisoire ? ID_DE_L_ETAT_DE_LA_SEANCE : undefined"
                dusk="workout-settings-button"
                :class="[
                    'text-text-muted flex size-11 shrink-0 items-center justify-center rounded-xl',
                    'border-surface-card bg-surface-card/60 border transition',
                    'focus-visible:ring-accent-primary focus-visible:ring-2 focus-visible:outline-none',
                ]"
                aria-label="Paramètres de la séance"
            >
                <GlassIcon name="settings" />
            </button>
        </template>

        <div class="pb-main-safe">
            <EtatDeLaSeanceEnAttente :etat="etatDeLaSeance" :seance="localWorkout" />
            <SeriesHorsDeLaCopie :seance="localWorkout.id" />
            <div ref="listeDesExercices" class="space-y-4" dusk="exercise-list">
                <GlassCard
                    v-if="localWorkout.workout_lines.length === 0"
                    class="flex flex-col items-center justify-center p-12 text-center"
                >
                    <h3 class="titre-section mb-4">Séance vide</h3>
                    <GlassButton
                        v-if="!lectureSeule"
                        variant="primary"
                        @click="showAddExercise = true"
                        dusk="add-first-exercise"
                        >Ajouter un exercice</GlassButton
                    >
                    <p v-else-if="isFinished" class="text-text-muted text-sm font-bold">Cette séance est terminée.</p>
                </GlassCard>

                <CarteDExercice
                    v-for="(line, lineIndex) in localWorkout.workout_lines"
                    :key="rowKey(line)"
                    :line="line"
                    :line-index="lineIndex"
                    :is-finished="lectureSeule"
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

            <ActionsDeLaSeance
                v-if="localWorkout.workout_lines.length > 0 && !lectureSeule"
                :enregistrement-du-modele="savingTemplate"
                :seance-en-attente="seanceProvisoire"
                @ajouter-un-exercice="showAddExercise = true"
                @demarrer-un-repos="openRestTimer"
                @enregistrer-comme-modele="saveAsTemplate"
                @terminer="finishWorkout"
            />
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

        <WorkoutFinishModal
            :show="showFinishModal"
            :en-attente="ecrituresEnAttente"
            :en-cours="clotureEnCours"
            @close="annulerLaCloture"
            @confirm="confirmFinishWorkout"
        />

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
            :reglage-desactive="surUneCopie"
            :raison-du-reglage="raisonDUneEcriture"
            :sortie-audio="sortieAudio"
            @finished="showTimer = false"
            @close="showTimer = false"
            @update:auto-rest-timer="setAutoRestTimer"
            dusk="rest-timer"
        />
    </AuthenticatedLayout>
</template>
