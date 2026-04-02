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
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import SyncService from '@/Utils/SyncService'
import Modal from '@/Components/UI/Modal.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import { Head, useForm, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
    categories: { type: Array, required: true },
    types: { type: Array, required: true },
})

// ⚡ Perf: Use a mutable reactive ref instead of computed to support optimistic UI updates
const localWorkout = ref(JSON.parse(JSON.stringify(props.workout)))
if (localWorkout.value.workout_lines && !Array.isArray(localWorkout.value.workout_lines)) {
    localWorkout.value.workout_lines = Object.values(localWorkout.value.workout_lines)
}

// Sync with Inertia props when they change (e.g. after redirect-based actions)
watch(
    () => props.workout,
    (newVal) => {
        localWorkout.value = JSON.parse(JSON.stringify(newVal))
        if (localWorkout.value.workout_lines && !Array.isArray(localWorkout.value.workout_lines)) {
            localWorkout.value.workout_lines = Object.values(localWorkout.value.workout_lines)
        }
    },
)

const showTimer = ref(false)
const timerDuration = ref(90)

let tempIdCounter = 0

const toggleSetCompletion = (set, exerciseRestTime) => {
    const newState = !set.is_completed
    const previousState = set.is_completed

    // ⚡ Perf: Optimistic update — no router.reload
    set.is_completed = newState
    if (newState) {
        timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
        showTimer.value = true
    }
    SyncService.patch(route('api.v1.sets.update', { set: set.id }), {
        is_completed: newState,
    })
        .then((response) => {
            if (response.data?.data) {
                Object.assign(set, response.data.data)
            }
        })
        .catch((err) => {
            if (!err.isOffline) {
                set.is_completed = previousState
                triggerHaptic('error')
            }
        })
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
const confirmFinishWorkout = () => {
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
const searchQuery = ref('')
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

    SyncService.post(route('api.v1.workout-lines.store'), {
        workout_id: localWorkout.value.id,
        exercise_id: exerciseId,
    })
        .then((response) => {
            // Replace temp line with server data
            const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === tempLine.id)
            if (idx !== -1 && response.data?.data) {
                localWorkout.value.workout_lines[idx] = response.data.data
            }
        })
        .catch((err) => {
            if (!err.isOffline) {
                // Rollback
                const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === tempLine.id)
                if (idx !== -1) localWorkout.value.workout_lines.splice(idx, 1)
                triggerHaptic('error')
            }
        })
}

const createAndAddExercise = async () => {
    createExerciseForm.processing = true
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
        }
    } catch (e) {
        console.error(e)
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
            const fields = ['weight', 'reps', 'distance_km', 'duration_seconds']
            fields.forEach((field) => {
                const timerKey = `${set.id}_${field}`
                if (updateTimers[timerKey]) {
                    clearTimeout(updateTimers[timerKey])
                    delete updateTimers[timerKey]
                }
            })
        })

        // ⚡ Perf: Optimistic removal
        const idx = localWorkout.value.workout_lines.findIndex((l) => l.id === lineId)
        const removedLine = idx !== -1 ? localWorkout.value.workout_lines.splice(idx, 1)[0] : null
        showConfirmModal.value = false

        SyncService.delete(route('api.v1.workout-lines.destroy', { workout_line: lineId })).catch((err) => {
            if (!err.isOffline && removedLine) {
                localWorkout.value.workout_lines.splice(idx, 0, removedLine)
                triggerHaptic('error')
            }
        })
    }
    showConfirmModal.value = true
}

// ⚡ Perf: Optimistic addSet — no router.reload
const addSet = (lineId) => {
    const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)
    if (!line) return

    const lastSet = line.sets?.length > 0 ? line.sets[line.sets.length - 1] : null

    const weight = lastSet ? lastSet.weight : (line?.recommended_values?.weight ?? 0)
    const reps = lastSet ? lastSet.reps : (line?.recommended_values?.reps ?? 10)
    const distance = lastSet ? lastSet.distance_km : (line?.recommended_values?.distance_km ?? 0)
    const duration = lastSet ? lastSet.duration_seconds : (line?.recommended_values?.duration_seconds ?? 30)

    // Optimistic: add set immediately with temp ID
    const tempSet = {
        id: `temp-${++tempIdCounter}`,
        workout_line_id: lineId,
        is_completed: false,
        weight: weight,
        reps: reps,
        distance_km: distance,
        duration_seconds: duration,
        is_warmup: false,
    }
    line.sets.push(tempSet)

    SyncService.post(route('api.v1.sets.store'), {
        workout_line_id: lineId,
        is_completed: false,
        weight: weight,
        reps: reps,
        distance_km: distance,
        duration_seconds: duration,
    })
        .then((response) => {
            // Replace temp set with server data
            const setIdx = line.sets.findIndex((s) => s.id === tempSet.id)
            if (setIdx !== -1 && response.data?.data) {
                line.sets[setIdx] = response.data.data
            }
        })
        .catch((err) => {
            if (!err.isOffline) {
                const setIdx = line.sets.findIndex((s) => s.id === tempSet.id)
                if (setIdx !== -1) line.sets.splice(setIdx, 1)
                triggerHaptic('error')
            }
        })
}

// ⚡ Perf: Optimistic updateSet — no router.reload
const updateTimers = {}
const updateSet = (set, field, value) => {
    const previousValue = set[field]
    set[field] = value
    const timerKey = `${set.id}_${field}`
    if (updateTimers[timerKey]) clearTimeout(updateTimers[timerKey])
    updateTimers[timerKey] = setTimeout(() => {
        SyncService.patch(route('api.v1.sets.update', { set: set.id }), { [field]: value })
            .then((response) => {
                if (response.data?.data) {
                    Object.assign(set, response.data.data)
                }
            })
            .catch((err) => {
                if (!err.isOffline) {
                    set[field] = previousValue
                    triggerHaptic('error')
                }
            })
        delete updateTimers[timerKey]
    }, 1000)
}

// ⚡ Perf: Optimistic removeSet — no router.reload
const removeSet = (setId) => {
    // Clear any pending updates for this set to prevent 404s
    const fields = ['weight', 'reps', 'distance_km', 'duration_seconds']
    fields.forEach((field) => {
        const timerKey = `${setId}_${field}`
        if (updateTimers[timerKey]) {
            clearTimeout(updateTimers[timerKey])
            delete updateTimers[timerKey]
        }
    })

    // Find the line and set
    for (const line of localWorkout.value.workout_lines) {
        const setIdx = line.sets.findIndex((s) => s.id === setId)
        if (setIdx !== -1) {
            const removedSet = line.sets.splice(setIdx, 1)[0]
            SyncService.delete(route('api.v1.sets.destroy', { set: setId })).catch((err) => {
                if (!err.isOffline) {
                    line.sets.splice(setIdx, 0, removedSet)
                    triggerHaptic('error')
                }
            })
            break
        }
    }
}

const createExerciseForm = useForm({ name: '', type: 'strength', category: 'Pectoraux' })
const categoriesList = ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio']
const typesList = [
    { value: 'strength', label: 'Force' },
    { value: 'cardio', label: 'Cardio' },
    { value: 'timed', label: 'Temps' },
]

const secondsToTime = (s) => (s ? new Date(s * 1000).toISOString().substr(11, 8) : '00:00:00')
const updateDurationFromTime = (set, val) => {
    const [h, m, s] = val.split(':').map(Number)
    updateSet(set, 'duration_seconds', h * 3600 + m * 60 + s)
}

const filteredExercises = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()
    return q ? localExercises.value.filter((e) => e.name.toLowerCase().includes(q)) : localExercises.value
})
</script>

<template>
    <Head :title="localWorkout.name || 'Séance'" />
    <AuthenticatedLayout :page-title="localWorkout.name" :show-back="true" back-route="workouts.index">
        <template #header-actions>
            <button
                v-press
                @click="showSettingsModal = true"
                dusk="workout-settings-button"
                class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-white backdrop-blur-md transition-all"
                aria-label="Paramètres de la séance"
            >
                <span class="material-symbols-outlined" aria-hidden="true">settings</span>
            </button>
        </template>

        <div class="space-y-4 pb-64" dusk="exercise-list">
            <GlassCard
                v-if="localWorkout.workout_lines.length === 0"
                class="flex flex-col items-center justify-center p-12 text-center"
            >
                <h3 class="font-display text-text-main mb-4 text-2xl font-black uppercase italic">Séance vide</h3>
                <GlassButton variant="primary" @click="showAddExercise = true" dusk="add-first-exercise"
                    >Ajouter un exercice</GlassButton
                >
            </GlassCard>

            <GlassCard
                v-for="(line, lineIndex) in localWorkout.workout_lines"
                :key="line.id"
                :dusk="`exercise-card-${lineIndex}`"
                :data-line-id="line.id"
                :dusk-id="`exercise-line-${line.id}`"
            >
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            {{ line.exercise.name }}
                        </h3>
                        <p class="text-text-muted text-xs font-bold uppercase">{{ line.exercise.category }}</p>
                    </div>
                    <button
                        v-press="{ haptic: 'warning' }"
                        @click="removeLine(line.id)"
                        :dusk="`remove-line-${lineIndex}`"
                        class="text-text-muted transition-colors hover:text-red-500"
                        aria-label="Supprimer l'exercice"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </button>
                </div>

                <div class="space-y-2">
                    <SwipeableRow v-for="(set, index) in line.sets" :key="`${set.id}-${index}`">
                        <div
                            class="flex items-center gap-3 rounded-2xl border border-white bg-white/80 p-4 shadow-sm"
                            :class="{ 'opacity-50': set.is_completed }"
                        >
                            <button
                                v-press
                                @click="toggleSetCompletion(set, line.exercise.default_rest_time)"
                                :dusk="`complete-set-${lineIndex}-${index}`"
                                class="group relative flex h-10 w-10 items-center justify-center rounded-xl border-2 transition-all"
                                :class="
                                    set.is_completed ? 'bg-neon-green text-text-main' : 'bg-slate-100 text-slate-300'
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
                                    v-if="set.personal_record || set.personalRecord"
                                    class="absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full bg-amber-500 text-white shadow-sm"
                                    :dusk="`pr-trophy-${lineIndex}-${index}`"
                                >
                                    <span class="material-symbols-outlined text-[12px] font-bold" aria-hidden="true"
                                        >stars</span
                                    >
                                </div>
                            </button>
                            <div
                                class="text-text-muted flex h-11 w-8 items-center justify-center rounded-lg bg-slate-100 text-sm font-black"
                            >
                                {{ index + 1 }}
                            </div>

                            <template v-if="line.exercise.type === 'strength'">
                                <input
                                    type="number"
                                    v-model="set.weight"
                                    @change="(e) => updateSet(set, 'weight', e.target.value)"
                                    :dusk="`weight-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-20 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                                <span class="text-text-muted text-xs font-bold">kg</span>
                                <input
                                    type="number"
                                    v-model="set.reps"
                                    @change="(e) => updateSet(set, 'reps', e.target.value)"
                                    :dusk="`reps-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-20 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                                <span class="text-text-muted text-xs font-bold">reps</span>
                            </template>

                            <template v-else-if="line.exercise.type === 'cardio'">
                                <input
                                    type="number"
                                    step="0.1"
                                    v-model="set.distance_km"
                                    @change="(e) => updateSet(set, 'distance_km', e.target.value)"
                                    :dusk="`distance-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-20 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                                <span class="text-text-muted text-xs font-bold">km</span>
                                <input
                                    type="time"
                                    step="1"
                                    :value="secondsToTime(set.duration_seconds)"
                                    @input="(e) => updateDurationFromTime(set, e.target.value)"
                                    :dusk="`duration-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-32 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                            </template>

                            <template v-else-if="line.exercise.type === 'timed'">
                                <input
                                    type="time"
                                    step="1"
                                    :value="secondsToTime(set.duration_seconds)"
                                    @input="(e) => updateDurationFromTime(set, e.target.value)"
                                    :dusk="`duration-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-full rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                            </template>

                            <button
                                v-press="{ haptic: 'warning' }"
                                @click="removeSet(set.id)"
                                :dusk="`remove-set-${lineIndex}-${index}`"
                                class="ml-auto text-slate-300 hover:text-red-500"
                                aria-label="Supprimer la série"
                            >
                                <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                            </button>
                        </div>
                    </SwipeableRow>
                </div>

                <button
                    v-press
                    @click="addSet(line.id)"
                    :dusk="`add-set-${lineIndex}`"
                    class="text-text-muted hover:border-neon-green mt-4 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-200 py-3 text-sm font-bold uppercase transition-all"
                >
                    Ajouter une série
                </button>
            </GlassCard>

            <div v-if="localWorkout.workout_lines.length > 0" class="mt-8 space-y-3 px-1">
                <GlassButton
                    variant="secondary"
                    @click="showAddExercise = true"
                    class="w-full"
                    dusk="add-exercise-existing"
                    >Ajouter un exercice</GlassButton
                >
                <div class="grid grid-cols-2 gap-3">
                    <GlassButton variant="solid" @click="saveAsTemplate" :loading="savingTemplate" class="w-full"
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
        <Modal :show="showAddExercise" @close="closeModal" max-width="lg">
            <div class="p-6">
                <h2 class="font-display text-text-main mb-6 text-2xl font-black uppercase italic">
                    Ajouter un exercice
                </h2>
                <div v-if="!showCreateForm">
                    <div class="sticky top-0 z-10 bg-white/50 pt-1 pb-4 backdrop-blur-sm dark:bg-slate-900/50">
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
                    <div class="max-h-[60vh] space-y-3 overflow-y-auto pb-64">
                        <div
                            v-if="filteredExercises.length === 0 && searchQuery"
                            @click="quickCreate"
                            dusk="quick-create-exercise"
                            class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center transition-all hover:border-emerald-500"
                        >
                            <p class="text-text-muted mb-2 text-sm italic">Aucun résultat pour "{{ searchQuery }}"</p>
                            <span class="font-bold tracking-wider text-emerald-600 uppercase"
                                >Créer "{{ searchQuery }}"</span
                            >
                        </div>

                        <div
                            v-for="exercise in filteredExercises"
                            :key="exercise.id"
                            @click="addExercise(exercise.id)"
                            :dusk="`select-exercise-${exercise.id}`"
                            class="glass-panel-light hover:border-electric-orange/50 cursor-pointer rounded-2xl p-4 transition-all"
                        >
                            <h4 class="text-text-main font-bold dark:text-white">{{ exercise.name }}</h4>
                            <p class="text-text-muted text-xs uppercase">{{ exercise.category }}</p>
                        </div>
                    </div>
                </div>

                <!-- Create Form -->
                <div v-else class="space-y-6">
                    <div class="flex items-center gap-4">
                        <button
                            @click="showCreateForm = false"
                            class="text-text-muted hover:text-text-main"
                            aria-label="Retour"
                        >
                            <span class="material-symbols-outlined">arrow_back</span>
                        </button>
                        <h3 class="font-display text-text-main text-xl font-black uppercase italic">Nouvel Exercice</h3>
                    </div>

                    <form @submit.prevent="createAndAddExercise" class="space-y-4">
                        <GlassInput v-model="createExerciseForm.name" label="Nom" dusk="new-exercise-name" required />

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="font-display-label text-text-muted mb-2 block">Type</label>
                                <select
                                    v-model="createExerciseForm.type"
                                    class="glass-input w-full"
                                    dusk="new-exercise-type"
                                >
                                    <option v-for="t in typesList" :key="t.value" :value="t.value">
                                        {{ t.label }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="font-display-label text-text-muted mb-2 block">Catégorie</label>
                                <select
                                    v-model="createExerciseForm.category"
                                    class="glass-input w-full"
                                    dusk="new-exercise-category"
                                >
                                    <option v-for="c in categoriesList" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
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

        <Modal :show="showConfirmModal" @close="showConfirmModal = false" max-width="sm">
            <div class="p-6 text-center">
                <h3 class="text-text-main mb-6 text-xl font-bold">{{ confirmMessage }}</h3>
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
            :duration="timerDuration"
            @finished="showTimer = false"
            @close="showTimer = false"
            dusk="rest-timer"
        />
    </AuthenticatedLayout>
</template>
