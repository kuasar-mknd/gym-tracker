<!--
  Workouts/Show.vue - Active Workout Page
  Mobile-first design with glass cards for exercise logging.
-->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import SyncService from '@/Utils/SyncService'
import Modal from '@/Components/Modal.vue'
import { Head, useForm, router, usePage, Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
    categories: { type: Array, required: true },
    types: { type: Array, required: true },
})

// Use computed directly for data display to ensure perfect sync with Inertia props
const localWorkout = computed(() => props.workout)

const showTimer = ref(false)
const timerDuration = ref(90)

const toggleSetCompletion = (set, exerciseRestTime) => {
    const newState = !set.is_completed
    const previousState = set.is_completed

    set.is_completed = newState
    triggerHaptic('tap')
    if (newState) {
        timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
        showTimer.value = true
    }
    SyncService.patch(route('api.v1.sets.update', { set: set.id }), { is_completed: newState })
        .then(() => {
            router.reload({ preserveScroll: true, only: ['workout'] })
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

const addExercise = (exerciseId) => {
    router.post(
        route('workout-lines.store', { workout: localWorkout.value.id }),
        { exercise_id: exerciseId },
        {
            preserveScroll: true,
            onSuccess: () => {
                showAddExercise.value = false
                searchQuery.value = ''
            },
        },
    )
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
            router.post(
                route('workout-lines.store', { workout: localWorkout.value.id }),
                { exercise_id: exercise.id },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        showCreateForm.value = false
                        showAddExercise.value = false
                        searchQuery.value = ''
                    },
                },
            )
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
    triggerHaptic('warning')

    confirmAction.value = () => {
        router.delete(route('workout-lines.destroy', { workout_line: lineId }), {
            preserveScroll: true,
            onSuccess: () => {
                showConfirmModal.value = false
            },
        })
    }
    showConfirmModal.value = true
}

const addSet = (lineId) => {
    triggerHaptic('tap')
    const line = localWorkout.value.workout_lines.find((l) => l.id === lineId)
    const lastSet = line?.sets?.length > 0 ? line.sets[line.sets.length - 1] : null

    // Use last set values if available (UX), otherwise recommended values from backend, fallback to defaults
    const weight = lastSet ? lastSet.weight : (line?.recommended_values?.weight ?? 0)
    const reps = lastSet ? lastSet.reps : (line?.recommended_values?.reps ?? 10)
    const distance = lastSet ? lastSet.distance_km : (line?.recommended_values?.distance_km ?? 0)
    const duration = lastSet ? lastSet.duration_seconds : (line?.recommended_values?.duration_seconds ?? 30)

    SyncService.post(route('api.v1.sets.store'), {
        workout_line_id: lineId,
        is_completed: false,
        weight: weight,
        reps: reps,
        distance_km: distance,
        duration_seconds: duration,
    }).then(() => {
        router.reload({ preserveScroll: true, only: ['workout'] })
    })
}

const updateTimers = {}
const updateSet = (set, field, value) => {
    set[field] = value
    const timerKey = `${set.id}_${field}`
    if (updateTimers[timerKey]) clearTimeout(updateTimers[timerKey])
    updateTimers[timerKey] = setTimeout(() => {
        SyncService.patch(route('api.v1.sets.update', { set: set.id }), { [field]: value }).then(() => {
            router.reload({ preserveScroll: true, only: ['workout'] })
        })
        delete updateTimers[timerKey]
    }, 1000)
}

const removeSet = (setId) => {
    triggerHaptic('warning')
    SyncService.delete(route('api.v1.sets.destroy', { set: setId })).then(() => {
        router.reload({ preserveScroll: true, only: ['workout'] })
    })
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
                @click="showSettingsModal = true"
                class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-white backdrop-blur-md transition-all active:scale-95"
            >
                <span class="material-symbols-outlined">settings</span>
            </button>
        </template>

        <div class="space-y-4 pb-64">
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
            >
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            {{ line.exercise.name }}
                        </h3>
                        <p class="text-text-muted text-xs font-bold uppercase">{{ line.exercise.category }}</p>
                    </div>
                    <button @click="removeLine(line.id)" class="text-text-muted transition-colors hover:text-red-500">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                                @click="toggleSetCompletion(set, line.exercise.default_rest_time)"
                                :dusk="`complete-set-${lineIndex}-${index}`"
                                class="group relative flex h-10 w-10 items-center justify-center rounded-xl border-2 transition-all"
                                :class="
                                    set.is_completed ? 'bg-neon-green text-text-main' : 'bg-slate-100 text-slate-300'
                                "
                            >
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                                    <span class="material-symbols-outlined text-[12px] font-bold">stars</span>
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

                            <button @click="removeSet(set.id)" class="ml-auto text-slate-300 hover:text-red-500">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </SwipeableRow>
                </div>

                <button
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
                        <input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Rechercher..."
                            class="text-text-main focus:border-electric-orange/50 w-full rounded-2xl border-2 border-slate-100 p-4 shadow-sm focus:ring-0 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
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
                        <button @click="showCreateForm = false" class="text-text-muted hover:text-text-main">
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

        <Modal :show="showSettingsModal" @close="showSettingsModal = false" max-width="lg">
            <div class="p-6">
                <h2 class="font-display text-text-main mb-6 text-2xl font-black uppercase italic">Paramètres</h2>
                <form @submit.prevent="updateSettings" class="space-y-5">
                    <GlassInput v-model="settingsForm.name" label="Nom" />
                    <GlassInput v-model="settingsForm.started_at" type="datetime-local" label="Date" />
                    <GlassButton type="submit" variant="primary" :loading="settingsForm.processing" class="w-full"
                        >Sauvegarder</GlassButton
                    >
                </form>
            </div>
        </Modal>

        <Modal :show="showFinishModal" @close="showFinishModal = false" max-width="sm">
            <div class="p-6 text-center">
                <h3
                    class="font-display text-text-main mb-6 text-xl font-black uppercase italic"
                    dusk="finish-workout-modal-title"
                >
                    Terminer la séance ?
                </h3>
                <div class="flex gap-3">
                    <GlassButton variant="secondary" @click="showFinishModal = false" class="flex-1"
                        >Annuler</GlassButton
                    >
                    <GlassButton
                        variant="primary"
                        id="confirm-finish-button"
                        dusk="confirm-finish-button"
                        @click="confirmFinishWorkout"
                        class="flex-1"
                        >Confirmer</GlassButton
                    >
                </div>
            </div>
        </Modal>

        <Modal :show="showConfirmModal" @close="showConfirmModal = false" max-width="sm">
            <div class="p-6 text-center">
                <h3 class="text-text-main mb-6 text-xl font-bold">{{ confirmMessage }}</h3>
                <div class="flex gap-3">
                    <GlassButton variant="secondary" @click="showConfirmModal = false" class="flex-1"
                        >Annuler</GlassButton
                    >
                    <GlassButton variant="danger" @click="confirmAction" class="flex-1">Supprimer</GlassButton>
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
