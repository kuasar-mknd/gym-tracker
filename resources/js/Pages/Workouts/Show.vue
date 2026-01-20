<!--
  Workouts/Show.vue - Active Workout Page
  Mobile-first design with glass cards for exercise logging.

  This component handles the active workout session. It allows users to:
  - View and manage exercises (WorkoutLines) and their sets.
  - Add new exercises (from existing list or create new ones).
  - Log weight and reps for each set.
  - Mark sets as complete, triggering a rest timer.
  - Save the workout structure as a template.
  - Delete exercises and sets.
-->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import Modal from '@/Components/Modal.vue'
import { Head, useForm, router, usePage, Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'

/**
 * Component Props
 * @property {Object} workout - The current workout object including relations (workout_lines, sets, exercises).
 * @property {Array} exercises - List of all available exercises for selection.
 * @property {Array} categories - List of exercise categories (e.g., 'Pectoraux', 'Dos').
 * @property {Array} types - List of exercise types (e.g., 'strength', 'cardio').
 */
const props = defineProps({
    workout: Object,
    exercises: Array,
    categories: {
        type: Array,
        default: () => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
    },
    types: {
        type: Array,
        default: () => [
            { value: 'strength', label: 'Force' },
            { value: 'cardio', label: 'Cardio' },
            { value: 'timed', label: 'Temps' },
        ],
    },
})

// --- State Management ---

/** Controls the visibility of the Rest Timer modal. */
const showTimer = ref(false)

/** Duration for the rest timer in seconds. */
const timerDuration = ref(90)

/**
 * Marks a set as completed/incomplete and triggers the rest timer if completed.
 *
 * @param {Object} set - The set object to update.
 * @param {Number|null} exerciseRestTime - The default rest time for the exercise.
 */
const toggleSetCompletion = (set, exerciseRestTime) => {
    const newState = !set.is_completed
    router.patch(
        route('sets.update', { set: set.id }),
        { is_completed: newState },
        {
            preserveScroll: true,
            only: ['workout'],
            onSuccess: () => {
                if (newState) {
                    timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
                    showTimer.value = true
                }
            },
        },
    )
}

/** Controls the loading state when saving as template. */
const savingTemplate = ref(false)

/**
 * Saves the current workout structure (exercises and sets) as a reusable template.
 */
const saveAsTemplate = () => {
    savingTemplate.value = true
    router.post(
        route('templates.save-from-workout', { workout: props.workout.id }),
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

const confirmFinishWorkout = () => {
    router.patch(
        route('workouts.update', { workout: props.workout.id }),
        { is_finished: true },
        {
            onSuccess: () => {
                showFinishModal.value = false
                router.visit(route('dashboard'))
            },
        },
    )
}

const deleteWorkout = () => {
    confirmMessage.value = 'Êtes-vous sûr de vouloir supprimer définitivement cette séance ?'
    confirmAction.value = () => {
        router.delete(route('workouts.destroy', { workout: props.workout.id }))
    }
    showConfirmModal.value = true
}

/** Controls visibility of the "Add Exercise" modal. */
const showAddExercise = ref(false)

/** Search query for filtering exercises. */
const searchQuery = ref('')

/** Controls visibility of the "Create New Exercise" form within the modal. */
const showCreateForm = ref(false)

/** Local copy of exercises to allow immediate updates when creating new ones. */
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))

/** Controls visibility of the deletion confirmation modal. */
const showConfirmModal = ref(false)

/** Callback function to execute upon confirmation. */
const confirmAction = ref(null)

/** Message displayed in the confirmation modal. */
const confirmMessage = ref('')

/** Controls visibility of the workout settings modal. */
const showSettingsModal = ref(false)

/** Form for editing workout details (name, date, notes). */
const settingsForm = useForm({
    name: props.workout.name,
    started_at: formatToLocalISO(props.workout.started_at),
    notes: props.workout.notes || '',
})

/**
 * Updates the workout settings (name, date, notes).
 */
const updateSettings = () => {
    settingsForm
        .transform((data) => ({
            ...data,
            started_at: formatToUTC(data.started_at),
        }))
        .patch(route('workouts.update', { workout: props.workout.id }), {
            preserveScroll: true,
            onSuccess: () => {
                showSettingsModal.value = false
            },
        })
}

/** Form for adding an existing exercise to the workout. */
const addExerciseForm = useForm({
    exercise_id: '',
})

/** Form for creating a new custom exercise. */
const createExerciseForm = useForm({
    name: '',
    type: 'strength',
    category: '',
})

/**
 * Adds an existing exercise to the workout.
 *
 * @param {Number} exerciseId - The ID of the exercise to add.
 */
const addExercise = (exerciseId) => {
    addExerciseForm.exercise_id = exerciseId
    addExerciseForm.post(route('workout-lines.store', { workout: props.workout.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showAddExercise.value = false
            searchQuery.value = ''
        },
    })
}

/**
 * Creates a new exercise via API and immediately adds it to the current workout.
 *
 * This handles a complex flow:
 * 1. POST to /exercises to create the global exercise record.
 * 2. Updates local exercise list.
 * 3. POST to /workout-lines to add it to the workout.
 */
const createAndAddExercise = async () => {
    createExerciseForm.processing = true
    createExerciseForm.clearErrors()

    try {
        const response = await fetch(route('exercises.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Quick-Create': 'true',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: createExerciseForm.name,
                type: createExerciseForm.type,
                category: createExerciseForm.category,
            }),
        })

        if (response.ok) {
            const responseData = await response.json()
            // Handle different response structures: {exercise: {...}} or {data: {...}} or direct {...}
            const exercise = responseData.exercise || responseData.data || responseData

            if (!exercise || !exercise.id) {
                console.error('Invalid exercise response:', responseData)
                createExerciseForm.processing = false
                return
            }

            // Add the new exercise to local list immediately
            localExercises.value.push(exercise)
            localExercises.value.sort((a, b) => a.name.localeCompare(b.name))

            // Reset form state
            createExerciseForm.reset()
            showCreateForm.value = false

            // Add the new exercise to the workout using Inertia router
            router.post(
                route('workout-lines.store', { workout: props.workout.id }),
                { exercise_id: exercise.id },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        showAddExercise.value = false
                        searchQuery.value = ''
                        createExerciseForm.processing = false
                    },
                    onError: () => {
                        createExerciseForm.processing = false
                    },
                    onFinish: () => {
                        createExerciseForm.processing = false
                    },
                },
            )
        } else if (response.status === 422) {
            // Validation errors
            const errors = await response.json()
            if (errors.errors) {
                Object.keys(errors.errors).forEach((key) => {
                    createExerciseForm.setError(key, errors.errors[key][0])
                })
            }
            createExerciseForm.processing = false
        } else {
            createExerciseForm.processing = false
        }
    } catch (error) {
        console.error('Error creating exercise:', error)
        createExerciseForm.processing = false
    }
}

/** Pre-fills the create form with the current search query. */
const quickCreate = () => {
    createExerciseForm.name = searchQuery.value
    showCreateForm.value = true
}

/** Cancels creation and resets the form. */
const cancelCreate = () => {
    showCreateForm.value = false
    createExerciseForm.reset()
}

/** Closes the add/create exercise modal and resets state. */
const closeModal = () => {
    showAddExercise.value = false
    showCreateForm.value = false
    searchQuery.value = ''
}

/**
 * Removes an exercise (WorkoutLine) from the workout.
 * Requires user confirmation via modal.
 *
 * @param {Number} lineId - The ID of the WorkoutLine to remove.
 */
const removeLine = (lineId) => {
    confirmMessage.value = 'Supprimer cet exercice de la séance ?'
    confirmAction.value = () => {
        router.delete(route('workout-lines.destroy', { workoutLine: lineId }), {
            preserveScroll: true,
        })
        showConfirmModal.value = false
    }
    showConfirmModal.value = true
}

/** Closes the confirmation modal. */
const cancelConfirm = () => {
    showConfirmModal.value = false
    confirmAction.value = null
}

const secondsToTime = (totalSeconds) => {
    if (!totalSeconds && totalSeconds !== 0) return ''
    const date = new Date(0)
    date.setSeconds(totalSeconds)
    return date.toISOString().substr(11, 8) // HH:mm:ss
}

const updateDurationFromTime = (set, timeString) => {
    if (!timeString) return
    const [hours, minutes, seconds] = timeString.split(':').map(Number)
    const totalSeconds = hours * 3600 + minutes * 60 + (seconds || 0)
    updateSet(set, 'duration_seconds', totalSeconds)
}

/**
 * Adds a new set to a specific exercise line.
 * Copies weight and reps from the previous set if available.
 *
 * @param {Number} lineId - The ID of the WorkoutLine.
 */
const addSet = (lineId) => {
    const line = props.workout.workout_lines.find((l) => l.id === lineId)
    const lastSet = line.sets.at(-1)
    const type = line.exercise.type

    const data = {
        weight: lastSet ? lastSet.weight : 0,
        reps: lastSet ? lastSet.reps : 10,
        distance_km: lastSet ? lastSet.distance_km : 0,
        duration_seconds: lastSet ? lastSet.duration_seconds : type === 'cardio' ? 600 : 30, // 10 min or 30 sec
    }

    if (type === 'cardio') {
        data.weight = 0
        data.reps = 0
    } else if (type === 'timed') {
        data.reps = 0
    } else {
        // strength
        data.distance_km = 0
        data.duration_seconds = 0
    }

    router.post(route('sets.store', { workoutLine: lineId }), data, {
        preserveScroll: true,
    })
}

/**
 * Updates a specific field (weight or reps) of a set.
 *
 * @param {Object} set - The set to update.
 * @param {String} field - The field name ('weight' or 'reps').
 * @param {Number} value - The new value.
 */
const updateSet = (set, field, value) => {
    router.patch(route('sets.update', { set: set.id }), { [field]: value }, { preserveScroll: true, only: ['workout'] })
}

/**
 * Deletes a set from the workout.
 *
 * @param {Number} setId - The ID of the set to delete.
 */
const removeSet = (setId) => {
    router.delete(route('sets.destroy', { set: setId }), { preserveScroll: true })
}

/**
 * Computed property to filter exercises based on search query.
 */
const filteredExercises = computed(() => {
    const exercises = localExercises.value.filter((e) => e && e.id)
    if (!searchQuery.value) return exercises
    return exercises.filter((e) => e.name?.toLowerCase().includes(searchQuery.value.toLowerCase()))
})

/**
 * Computed property checking if search yields no results.
 */
const hasNoResults = computed(() => {
    return searchQuery.value && filteredExercises.value.length === 0
})
</script>

<template>
    <Head :title="workout.name || 'Séance'" />

    <AuthenticatedLayout :page-title="workout.name || 'Séance en cours'" show-back back-route="workouts.index">
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link
                        :href="route('workouts.index')"
                        class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                    >
                        <span class="material-symbols-outlined">arrow_back</span>
                    </Link>
                    <h1 class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic">
                        {{ workout.name || 'Séance' }}
                    </h1>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="!workout.ended_at"
                        id="finish-workout-desktop"
                        @click="finishWorkout"
                        class="bg-electric-orange flex items-center gap-2 rounded-full px-4 py-2 text-xs font-black tracking-widest text-white uppercase shadow-lg shadow-orange-500/30 transition hover:bg-orange-600 active:scale-95"
                    >
                        <span class="material-symbols-outlined text-sm">stop_circle</span>
                        Terminer
                    </button>
                    <span
                        v-else
                        id="workout-status-badge-desktop"
                        class="glass-badge glass-badge-success flex items-center gap-1 rounded-full px-4 py-2 text-xs"
                    >
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        Terminée
                    </span>

                    <button
                        @click="showSettingsModal = true"
                        class="text-text-muted flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-all hover:bg-slate-50 active:scale-95"
                        title="Paramètres de la séance"
                    >
                        <span class="material-symbols-outlined">settings</span>
                    </button>

                    <button
                        @click="deleteWorkout"
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-red-200 bg-white text-red-500 shadow-sm transition-all hover:bg-red-50 active:scale-95"
                        title="Supprimer la séance"
                    >
                        <span class="material-symbols-outlined">delete</span>
                    </button>
                </div>
            </div>
        </template>

        <!-- Only keep template save button if needed somewhere else or secondary -->
        <template #header-actions>
            <button
                @click="showSettingsModal = true"
                class="text-text-muted flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-all hover:bg-slate-50 active:scale-95"
                title="Paramètres de la séance"
            >
                <span class="material-symbols-outlined">settings</span>
            </button>

            <button
                v-if="!workout.ended_at"
                @click="showAddExercise = true"
                class="bg-electric-orange flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white shadow-lg shadow-orange-500/30 transition-all active:scale-95"
                title="Ajouter un exercice"
            >
                <span class="material-symbols-outlined">add</span>
            </button>

            <button
                v-if="!workout.ended_at"
                id="finish-workout-mobile"
                @click="finishWorkout"
                class="border-electric-orange/20 bg-electric-orange/10 text-electric-orange hover:bg-electric-orange/20 flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-xs font-black tracking-widest uppercase transition active:scale-95"
            >
                <span class="material-symbols-outlined text-sm">stop_circle</span>
                Terminer
            </button>
            <span
                v-if="workout.ended_at"
                id="workout-status-badge-mobile"
                class="glass-badge glass-badge-success flex shrink-0 items-center gap-1 rounded-full px-4 py-2 text-xs"
            >
                <span class="material-symbols-outlined text-sm">check_circle</span>
                Terminée
            </span>
        </template>

        <div class="space-y-4">
            <!-- Exercise Cards -->
            <GlassCard v-for="line in workout.workout_lines" :key="line.id" class="animate-slide-up">
                <!-- Exercise Header -->
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-text-main text-xl font-black tracking-tight uppercase italic">
                            {{ line.exercise.name }}
                        </h3>
                        <p class="text-text-muted text-xs font-bold tracking-wider uppercase">
                            {{ line.exercise.category }}
                        </p>
                    </div>
                    <button
                        v-if="!workout.ended_at"
                        @click="removeLine(line.id)"
                        class="text-text-muted rounded-xl p-2 transition hover:bg-red-50 hover:text-red-500"
                        aria-label="Supprimer l'exercice"
                    >
                        <svg
                            class="h-5 w-5"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </button>
                </div>

                <!-- Sets List -->
                <div class="space-y-2">
                    <div
                        v-for="(set, index) in line.sets"
                        :key="set.id"
                        class="flex items-center gap-3 rounded-2xl border border-white bg-white/80 p-4 shadow-sm transition-all"
                        :class="{ 'bg-slate-50 opacity-50': set.is_completed }"
                    >
                        <!-- Complete Button -->
                        <button
                            @click="toggleSetCompletion(set, line.exercise.default_rest_time)"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition-all active:scale-90"
                            :class="[
                                set.is_completed
                                    ? 'bg-neon-green text-text-main shadow-neon'
                                    : 'hover:bg-neon-green/20 hover:text-neon-green bg-slate-100 text-slate-300',
                                { 'cursor-not-allowed opacity-50': workout.ended_at },
                            ]"
                            :disabled="!!workout.ended_at"
                            aria-label="Marquer comme complété"
                        >
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                        </button>

                        <!-- Set Number -->
                        <div
                            class="relative flex h-11 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-black transition-colors"
                            :class="
                                set.personal_record
                                    ? 'bg-amber-100 text-amber-600 ring-1 ring-amber-300'
                                    : 'text-text-muted bg-slate-100'
                            "
                        >
                            {{ index + 1 }}
                            <span v-if="set.personal_record" class="absolute -top-2 -right-2 text-sm">🏆</span>
                        </div>

                        <!-- INPUTS BASED ON EXERCISE TYPE -->

                        <!-- CARDIO: Distance (km) & Duration (min) -->
                        <template v-if="line.exercise.type === 'cardio'">
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="number"
                                    :value="set.distance_km"
                                    @change="(e) => updateSet(set, 'distance_km', e.target.value)"
                                    @focus="(e) => e.target.select()"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-20 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    inputmode="decimal"
                                    step="0.01"
                                    :aria-label="`${line.exercise.name} : Distance série ${index + 1}`"
                                />
                                <span class="text-text-muted text-xs font-bold uppercase">km</span>
                            </div>
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="time"
                                    step="1"
                                    :value="secondsToTime(set.duration_seconds)"
                                    @change="(e) => updateDurationFromTime(set, e.target.value)"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-32 min-w-0 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    aria-label="Durée"
                                />
                            </div>
                        </template>

                        <!-- TIMED: Weight (kg) & Duration (sec) -->
                        <template v-else-if="line.exercise.type === 'timed'">
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="number"
                                    :value="set.weight"
                                    @change="(e) => updateSet(set, 'weight', e.target.value)"
                                    @focus="(e) => e.target.select()"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-20 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    inputmode="decimal"
                                    placeholder="-"
                                    :aria-label="`${line.exercise.name} : Poids série ${index + 1}`"
                                />
                                <span class="text-text-muted text-xs font-bold uppercase">kg</span>
                            </div>
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="time"
                                    step="1"
                                    :value="secondsToTime(set.duration_seconds)"
                                    @change="(e) => updateDurationFromTime(set, e.target.value)"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-32 min-w-0 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    aria-label="Durée"
                                />
                            </div>
                        </template>

                        <!-- STRENGTH: Weight (kg) & Reps -->
                        <template v-else>
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="number"
                                    :value="set.weight"
                                    @change="(e) => updateSet(set, 'weight', e.target.value)"
                                    @focus="(e) => e.target.select()"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-20 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    inputmode="decimal"
                                    :aria-label="`${line.exercise.name} : Poids série ${index + 1}`"
                                />
                                <span class="text-text-muted text-xs font-bold uppercase">kg</span>
                            </div>
                            <div class="flex flex-1 items-center gap-2">
                                <input
                                    type="number"
                                    :value="set.reps"
                                    @change="(e) => updateSet(set, 'reps', e.target.value)"
                                    @focus="(e) => e.target.select()"
                                    class="text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-11 w-20 rounded-xl border-2 border-slate-200 bg-white px-2 py-2 text-center font-bold transition-all outline-none focus:ring-2 disabled:opacity-50"
                                    :disabled="set.is_completed || !!workout.ended_at"
                                    inputmode="numeric"
                                    :aria-label="`${line.exercise.name} : Répétitions série ${index + 1}`"
                                />
                                <span class="text-text-muted text-xs font-bold uppercase">reps</span>
                            </div>
                        </template>

                        <!-- Delete Set -->
                        <button
                            v-if="!workout.ended_at"
                            @click="removeSet(set.id)"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-slate-300 transition-all hover:bg-red-50 hover:text-red-500"
                            aria-label="Supprimer la série"
                        >
                            <svg
                                class="h-5 w-5"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Add Set Button -->
                <button
                    v-if="!workout.ended_at"
                    @click="addSet(line.id)"
                    class="text-text-muted hover:border-neon-green hover:bg-neon-green/5 hover:text-text-main mt-4 flex min-h-[52px] w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-200 bg-white/50 py-3 text-sm font-bold tracking-wider uppercase transition-all active:scale-[0.98]"
                >
                    <svg
                        class="h-5 w-5"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter une série
                </button>
            </GlassCard>

            <!-- Persistent Add Exercise Button (when not empty) -->
            <button
                v-if="!workout.ended_at && workout.workout_lines.length > 0"
                @click="showAddExercise = true"
                class="animate-slide-up text-text-muted hover:border-electric-orange hover:bg-electric-orange/5 hover:text-electric-orange flex min-h-[80px] w-full items-center justify-center gap-3 rounded-3xl border-2 border-dashed border-slate-200 bg-white/50 py-6 text-sm font-black tracking-widest uppercase transition-all active:scale-[0.98]"
            >
                <span class="material-symbols-outlined text-3xl">add_circle</span>
                Ajouter un exercice
            </button>

            <!-- Empty State -->
            <GlassCard v-if="workout.workout_lines.length === 0" class="animate-slide-up">
                <div class="py-12 text-center">
                    <div class="mb-3 text-5xl">🎯</div>
                    <h3 class="text-text-main text-lg font-bold">Séance vide</h3>
                    <p class="text-text-muted mt-1">Ajoute ton premier exercice</p>
                    <GlassButton
                        v-if="!workout.ended_at"
                        variant="primary"
                        class="mt-4"
                        @click="showAddExercise = true"
                    >
                        Ajouter un exercice
                    </GlassButton>
                </div>
            </GlassCard>
        </div>

        <!-- Add Exercise Modal -->
        <Teleport to="body">
            <div v-if="showAddExercise" class="glass-overlay animate-fade-in" @click.self="showAddExercise = false">
                <div
                    class="fixed inset-x-4 top-auto bottom-4 max-h-[80vh] sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2"
                >
                    <div class="glass-modal animate-slide-up overflow-hidden">
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between border-b border-slate-200 p-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                {{ showCreateForm ? 'Nouvel exercice' : 'Choisir un exercice' }}
                            </h3>
                            <button
                                @click="closeModal"
                                class="text-text-muted hover:text-text-main rounded-xl p-2 hover:bg-slate-100"
                                aria-label="Fermer"
                            >
                                <svg
                                    class="h-5 w-5"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>

                        <!-- Create Exercise Form -->
                        <div v-if="showCreateForm" class="p-4">
                            <form @submit.prevent="createAndAddExercise" class="space-y-4">
                                <GlassInput
                                    v-model="createExerciseForm.name"
                                    label="Nom de l'exercice"
                                    placeholder="Ex: Développé couché"
                                    :error="createExerciseForm.errors.name"
                                    autofocus
                                />
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label
                                            class="text-text-muted mb-2 block text-xs font-black tracking-widest uppercase"
                                            >Type</label
                                        >
                                        <select v-model="createExerciseForm.type" class="glass-input w-full text-sm">
                                            <option v-for="t in types" :key="t.value" :value="t.value">
                                                {{ t.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="text-text-muted mb-2 block text-xs font-black tracking-widest uppercase"
                                            >Catégorie</label
                                        >
                                        <select
                                            v-model="createExerciseForm.category"
                                            class="glass-input w-full text-sm"
                                        >
                                            <option value="">— Aucune —</option>
                                            <option v-for="cat in categories" :key="cat" :value="cat">
                                                {{ cat }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <GlassButton
                                        type="submit"
                                        variant="primary"
                                        class="flex-1"
                                        :loading="createExerciseForm.processing"
                                    >
                                        Créer et ajouter
                                    </GlassButton>
                                    <GlassButton type="button" variant="ghost" @click="cancelCreate">
                                        Annuler
                                    </GlassButton>
                                </div>
                            </form>
                        </div>

                        <!-- Search & List -->
                        <template v-else>
                            <!-- Search -->
                            <div class="p-4">
                                <GlassInput v-model="searchQuery" placeholder="Rechercher..." autofocus />
                            </div>

                            <!-- Exercise List or No Results -->
                            <div class="max-h-[50vh] overflow-y-auto p-4 pt-0">
                                <!-- No Results - Quick Create -->
                                <div v-if="hasNoResults" class="py-6 text-center">
                                    <p class="text-text-muted mb-3">Aucun exercice trouvé pour "{{ searchQuery }}"</p>
                                    <GlassButton variant="primary" @click="quickCreate">
                                        <svg
                                            class="mr-2 h-4 w-4"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 4v16m8-8H4"
                                            />
                                        </svg>
                                        Créer "{{ searchQuery }}"
                                    </GlassButton>
                                </div>

                                <!-- Exercise List -->
                                <div v-else class="space-y-2">
                                    <!-- Create New Button -->
                                    <button
                                        @click="showCreateForm = true"
                                        class="hover:border-electric-orange/30 hover:bg-electric-orange/5 flex w-full items-center gap-3 rounded-xl border-2 border-dashed border-slate-200 p-4 text-left transition"
                                    >
                                        <div
                                            class="bg-electric-orange/10 flex h-10 w-10 items-center justify-center rounded-lg"
                                        >
                                            <svg
                                                class="text-electric-orange h-5 w-5"
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M12 4v16m8-8H4"
                                                />
                                            </svg>
                                        </div>
                                        <div>
                                            <div class="text-text-main font-semibold">Créer un nouvel exercice</div>
                                            <div class="text-text-muted text-sm">Si tu ne trouves pas ton exercice</div>
                                        </div>
                                    </button>

                                    <!-- Existing Exercises -->
                                    <button
                                        v-for="exercise in filteredExercises"
                                        :key="exercise.id"
                                        @click="addExercise(exercise.id)"
                                        :disabled="addExerciseForm.processing"
                                        class="group flex w-full items-center justify-between rounded-xl p-4 text-left transition hover:bg-slate-50 disabled:opacity-50"
                                        :aria-label="`Ajouter ${exercise.name}`"
                                    >
                                        <div>
                                            <div class="text-text-main font-semibold">{{ exercise.name }}</div>
                                            <div class="text-text-muted text-sm">{{ exercise.category }}</div>
                                        </div>
                                        <span
                                            class="text-electric-orange text-2xl opacity-0 transition group-hover:opacity-100"
                                            >+</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Confirmation Modal -->
        <Teleport to="body">
            <div v-if="showConfirmModal" class="glass-overlay animate-fade-in" @click.self="cancelConfirm">
                <div
                    class="fixed inset-x-4 top-1/2 bottom-auto -translate-y-1/2 sm:inset-auto sm:left-1/2 sm:w-full sm:max-w-sm sm:-translate-x-1/2"
                >
                    <div class="glass-modal animate-slide-up overflow-hidden">
                        <div class="p-6 text-center">
                            <div
                                class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-500/20"
                            >
                                <svg
                                    class="h-7 w-7 text-red-400"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                    />
                                </svg>
                            </div>
                            <h3 class="text-text-main mb-2 text-lg font-bold">Confirmer la suppression</h3>
                            <p class="text-text-muted mb-6">{{ confirmMessage }}</p>
                            <div class="flex gap-3">
                                <GlassButton class="flex-1" @click="cancelConfirm"> Annuler </GlassButton>
                                <GlassButton variant="danger" class="flex-1" @click="confirmAction">
                                    Supprimer
                                </GlassButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Rest Timer -->
        <RestTimer
            v-if="showTimer"
            :duration="timerDuration"
            auto-start
            @finished="showTimer = false"
            @close="showTimer = false"
        />

        <!-- Workout Settings Modal -->
        <Teleport to="body">
            <div v-if="showSettingsModal" class="glass-overlay animate-fade-in" @click.self="showSettingsModal = false">
                <div
                    class="fixed inset-x-4 top-auto bottom-4 max-h-[90vh] sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2"
                >
                    <div class="glass-modal animate-slide-up overflow-hidden">
                        <div class="flex items-center justify-between border-b border-slate-200 p-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Paramètres de la séance
                            </h3>
                            <button
                                @click="showSettingsModal = false"
                                class="text-text-muted hover:text-text-main rounded-xl p-2 hover:bg-slate-100"
                            >
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>

                        <form @submit.prevent="updateSettings" class="space-y-4 p-6">
                            <GlassInput
                                v-model="settingsForm.name"
                                label="Nom de la séance"
                                placeholder="Ex: Séance Jambes"
                                :error="settingsForm.errors.name"
                            />

                            <GlassInput
                                v-model="settingsForm.started_at"
                                label="Date et heure"
                                type="datetime-local"
                                :error="settingsForm.errors.started_at"
                            />

                            <div>
                                <label class="text-text-muted mb-2 block text-xs font-black tracking-widest uppercase">
                                    Notes
                                </label>
                                <textarea
                                    v-model="settingsForm.notes"
                                    rows="4"
                                    class="text-text-main placeholder-text-muted/30 focus:border-electric-orange focus:ring-electric-orange w-full rounded-xl border border-slate-200 bg-white/50 px-4 py-2 backdrop-blur-md focus:ring-1 focus:outline-none"
                                    placeholder="Comment s'est passée votre séance ?"
                                ></textarea>
                                <div v-if="settingsForm.errors.notes" class="mt-1 text-xs text-red-500">
                                    {{ settingsForm.errors.notes }}
                                </div>
                            </div>

                            <div class="flex gap-3 pt-2">
                                <GlassButton
                                    type="button"
                                    variant="ghost"
                                    class="flex-1"
                                    @click="showSettingsModal = false"
                                >
                                    Annuler
                                </GlassButton>
                                <GlassButton
                                    type="submit"
                                    variant="primary"
                                    class="flex-1"
                                    :loading="settingsForm.processing"
                                >
                                    Enregistrer
                                </GlassButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Confirmation Modal -->
        <Modal :show="showFinishModal" @close="showFinishModal = false" maxWidth="sm">
            <div class="p-6">
                <div class="mb-5 flex items-center justify-center">
                    <div class="bg-electric-orange/20 flex h-16 w-16 items-center justify-center rounded-full">
                        <span class="material-symbols-outlined text-electric-orange text-4xl">check_circle</span>
                    </div>
                </div>

                <h2 class="font-display text-text-main mb-2 text-center text-xl font-black uppercase italic">
                    Terminer la séance ?
                </h2>
                <p class="text-text-muted mb-6 text-center text-sm">
                    Vous ne pourrez plus modifier cette séance. La durée sera enregistrée.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <GlassButton variant="ghost" @click="showFinishModal = false"> Annuler </GlassButton>
                    <GlassButton variant="primary" id="confirm-finish-button" @click="confirmFinishWorkout">
                        Confirmer
                    </GlassButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
