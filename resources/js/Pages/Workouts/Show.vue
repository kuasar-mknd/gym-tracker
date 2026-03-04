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
import { ref, computed, watch } from 'vue'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    exercises: { type: Array, required: true },
    categories: { type: Array, required: true },
    types: { type: Array, required: true },
})

// --- State Management ---
const localWorkout = ref(JSON.parse(JSON.stringify(props.workout)))
const workoutKey = ref(0)
const forceUpdate = () => {
    workoutKey.value++
}

// Track what's being edited to avoid overwriting it with props
const activeEditingId = ref(null)
const setEditing = (id) => {
    activeEditingId.value = id
}
const clearEditing = () => {
    // Small delay to ensure any pending debounce finishes
    setTimeout(() => {
        activeEditingId.value = null
    }, 600)
}

// Sync with props if they change externally
watch(
    () => props.workout,
    (newW) => {
        if (newW && newW.id === localWorkout.value.id) {
            // Merge logic: keep local values for the set being edited
            const mergedWorkout = JSON.parse(JSON.stringify(newW))

            if (activeEditingId.value) {
                console.log('Merge: preserve active editing id', activeEditingId.value)
                // Find the local set being edited to get its current values
                let localSetToPreserve = null
                localWorkout.value.workout_lines.forEach((line) => {
                    const found = line.sets.find((s) => s.id === activeEditingId.value)
                    if (found) localSetToPreserve = found
                })

                if (localSetToPreserve) {
                    console.log('Merge: found local set to preserve', localSetToPreserve)
                    mergedWorkout.workout_lines.forEach((line) => {
                        const setToUpdate = line.sets.find((s) => s.id === activeEditingId.value)
                        if (setToUpdate) {
                            console.log('Merge: applying local values to merged set', localSetToPreserve.weight)
                            setToUpdate.weight = localSetToPreserve.weight
                            setToUpdate.reps = localSetToPreserve.reps
                            setToUpdate.distance_km = localSetToPreserve.distance_km
                            setToUpdate.duration_seconds = localSetToPreserve.duration_seconds
                        }
                    })
                }
            }

            localWorkout.value = mergedWorkout
            forceUpdate()
        }
    },
    { deep: true },
)

const showTimer = ref(false)
const timerDuration = ref(90)

const toggleSetCompletion = (set, exerciseRestTime) => {
    const newState = !set.is_completed
    const previousState = set.is_completed

    // Flush any pending updates for this set immediately
    const timerKey = `${set.id}_weight`
    const repsKey = `${set.id}_reps`
    if (updateTimers[timerKey]) {
        clearTimeout(updateTimers[timerKey])
        SyncService.patch(route('api.v1.sets.update', { set: set.id }), { weight: set.weight })
        delete updateTimers[timerKey]
    }
    if (updateTimers[repsKey]) {
        clearTimeout(updateTimers[repsKey])
        SyncService.patch(route('api.v1.sets.update', { set: set.id }), { reps: set.reps })
        delete updateTimers[repsKey]
    }

    set.is_completed = newState
    triggerHaptic('tap')
    if (newState) {
        timerDuration.value = exerciseRestTime || usePage().props.auth.user.default_rest_time || 90
        showTimer.value = true
    }
    SyncService.patch(route('api.v1.sets.update', { set: set.id }), { is_completed: newState }).catch((err) => {
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
    try {
        // Appends a yellow block to prove the click executed
        document.body.innerHTML += `<div style="position:fixed;top:0;left:0;width:100%;z-index:999999;background:yellow;color:black;font-size:20px;padding:10px;">Clicked Confirm</div>`

        router.patch(
            route('workouts.update', { workout: localWorkout.value.id }),
            { is_finished: true },
            {
                onBefore: () => {
                    document.body.innerHTML += `<div style="position:fixed;top:40px;left:0;width:100%;z-index:999999;background:orange;color:black;font-size:20px;padding:10px;">Inertia onBefore</div>`
                },
                onStart: () => {
                    document.body.innerHTML += `<div style="position:fixed;top:80px;left:0;width:100%;z-index:999999;background:blue;color:white;font-size:20px;padding:10px;">Inertia onStart</div>`
                },
                onError: (e) => {
                    const msg = JSON.stringify(e)
                    document.body.innerHTML += `<div style="position:fixed;top:120px;left:0;width:100%;z-index:999999;background:red;color:white;font-size:20px;padding:10px;">Inertia onError: ${msg}</div>`
                },
                onSuccess: () => {
                    triggerHaptic('success')
                    showFinishModal.value = false
                },
            },
        )
    } catch (e) {
        const errorDiv = document.createElement('div')
        errorDiv.style.cssText =
            'position:fixed;top:160px;left:0;width:100%;z-index:999999;background:darkred;color:white;font-size:20px;padding:10px;'
        errorDiv.innerText = e.message || 'Unknown JS Error'
        document.body.appendChild(errorDiv)
        throw e
    }
}

const showAddExercise = ref(false)
const searchQuery = ref('')
const showCreateForm = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))
const showConfirmModal = ref(false)
const confirmAction = ref(null)
const confirmMessage = ''
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
                localWorkout.value.name = settingsForm.name
                localWorkout.value.notes = settingsForm.notes
                forceUpdate()
            },
        })
}

const addExercise = (exerciseId) => {
    router.post(
        route('workout-lines.store', { workout: localWorkout.value.id }),
        { exercise_id: exerciseId },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                showAddExercise.value = false
                searchQuery.value = ''
                localWorkout.value = JSON.parse(JSON.stringify(page.props.workout))
                forceUpdate()
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
            const exercise = (await response.json()).data
            localExercises.value.push(exercise)
            router.post(
                route('workout-lines.store', { workout: localWorkout.value.id }),
                { exercise_id: exercise.id },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        showCreateForm.value = false
                        localWorkout.value = JSON.parse(JSON.stringify(page.props.workout))
                        forceUpdate()
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
const cancelCreate = () => {
    showCreateForm.value = false
    createExerciseForm.reset()
}
const closeModal = () => {
    showAddExercise.value = false
    showCreateForm.value = false
    searchQuery.value = ''
}

const removeLine = (lineId) => {
    confirmAction.value = () => {
        const workout = JSON.parse(JSON.stringify(localWorkout.value))
        const idx = workout.workout_lines.findIndex((l) => l.id === lineId)
        if (idx !== -1) {
            workout.workout_lines.splice(idx, 1)
            localWorkout.value = workout
            forceUpdate()
            SyncService.delete(route('api.v1.workout-lines.destroy', { workout_line: lineId }))
        }
        showConfirmModal.value = false
    }
    showConfirmModal.value = true
}

const addSet = (lineId) => {
    const workout = JSON.parse(JSON.stringify(localWorkout.value))
    const lineIndex = workout.workout_lines.findIndex((l) => l.id === lineId)
    if (lineIndex === -1) return

    const line = workout.workout_lines[lineIndex]
    const lastSet = line.sets?.at(-1)
    const data = {
        weight: lastSet?.weight || 0,
        reps: lastSet?.reps || 10,
        distance_km: lastSet?.distance_km || 0,
        duration_seconds: lastSet?.duration_seconds || 30,
    }

    const tempId = 'temp_' + Date.now()
    const optimisticSet = { id: tempId, workout_line_id: lineId, is_completed: false, ...data }
    if (!line.sets) line.sets = []
    line.sets.push(optimisticSet)

    localWorkout.value = workout
    forceUpdate()

    SyncService.post(route('api.v1.sets.store'), { workout_line_id: lineId, is_completed: false, ...data }).then(
        (res) => {
            const current = JSON.parse(JSON.stringify(localWorkout.value))
            const lIdx = current.workout_lines.findIndex((l) => l.id === lineId)
            if (lIdx !== -1) {
                const sIdx = current.workout_lines[lIdx].sets.findIndex((s) => s.id === tempId)
                if (sIdx !== -1) {
                    current.workout_lines[lIdx].sets[sIdx] = res.data.data
                    localWorkout.value = current
                    forceUpdate()
                }
            }
        },
    )
}

const updateSet = (set, field, value) => {
    set[field] = value
    const timerKey = `${set.id}_${field}`
    if (updateTimers[timerKey]) clearTimeout(updateTimers[timerKey])
    updateTimers[timerKey] = setTimeout(() => {
        SyncService.patch(route('api.v1.sets.update', { set: set.id }), { [field]: value })
        delete updateTimers[timerKey]
    }, 500)
}
const updateTimers = {}

const removeSet = (setId) => {
    const workout = JSON.parse(JSON.stringify(localWorkout.value))
    for (const line of workout.workout_lines) {
        const sIdx = line.sets.findIndex((s) => s.id === setId)
        if (sIdx !== -1) {
            line.sets.splice(sIdx, 1)
            localWorkout.value = workout
            forceUpdate()
            SyncService.delete(route('api.v1.sets.destroy', { set: setId }))
            break
        }
    }
}

const createExerciseForm = useForm({ name: '', type: 'strength', category: '' })
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
        <template #actions>
            <button
                @click="showSettingsModal = true"
                class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 text-white backdrop-blur-md transition-all active:scale-95"
            >
                <span class="material-symbols-outlined">settings</span>
            </button>
        </template>

        <div class="space-y-4" :key="workoutKey">
            <div
                v-if="localWorkout.workout_lines.length === 0"
                class="glass-panel-light flex flex-col items-center justify-center p-12 text-center"
            >
                <h3 class="font-display text-text-main mb-4 text-2xl font-black uppercase italic">Séance vide</h3>
                <GlassButton variant="primary" @click="showAddExercise = true" dusk="add-first-exercise"
                    >Ajouter un exercice</GlassButton
                >
            </div>

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
                    <button
                        v-if="!localWorkout.ended_at"
                        @click="removeLine(line.id)"
                        class="text-text-muted transition-colors hover:text-red-500"
                    >
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
                    <SwipeableRow
                        v-for="(set, index) in line.sets"
                        :key="`${set.id}-${index}`"
                        :disabled="!!localWorkout.ended_at"
                    >
                        <div
                            class="flex items-center gap-3 rounded-2xl border border-white bg-white/80 p-4 shadow-sm"
                            :class="{ 'opacity-50': set.is_completed }"
                        >
                            <button
                                @click="toggleSetCompletion(set, line.exercise.default_rest_time)"
                                :dusk="`complete-set-${lineIndex}-${index}`"
                                class="group flex h-10 w-10 items-center justify-center rounded-xl border-2 transition-all"
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
                                    @focus="setEditing(set.id)"
                                    @blur="clearEditing"
                                    @click.stop
                                    @change="(e) => updateSet(set, 'weight', e.target.value)"
                                    :dusk="`weight-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-20 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                                <span class="text-text-muted text-xs font-bold">kg</span>
                                <input
                                    type="number"
                                    v-model="set.reps"
                                    @focus="setEditing(set.id)"
                                    @blur="clearEditing"
                                    @click.stop
                                    @change="(e) => updateSet(set, 'reps', e.target.value)"
                                    :dusk="`reps-input-${lineIndex}-${index}`"
                                    class="text-text-main h-11 w-20 rounded-xl border-2 border-slate-200 text-center font-bold"
                                />
                                <span class="text-text-muted text-xs font-bold">reps</span>
                            </template>

                            <button
                                v-if="!localWorkout.ended_at"
                                @click="removeSet(set.id)"
                                class="ml-auto text-slate-300 hover:text-red-500"
                            >
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </SwipeableRow>
                </div>

                <button
                    v-if="!localWorkout.ended_at"
                    @click="addSet(line.id)"
                    :dusk="`add-set-${lineIndex}`"
                    class="text-text-muted hover:border-neon-green mt-4 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-200 py-3 text-sm font-bold uppercase transition-all"
                >
                    Ajouter une série
                </button>
            </GlassCard>

            <div v-if="localWorkout.workout_lines.length > 0 && !localWorkout.ended_at" class="mt-8 space-y-3 px-1">
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
                    <GlassButton variant="primary" @click="finishWorkout" class="w-full" id="finish-workout-mobile"
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
                <h3 class="font-display text-text-main mb-6 text-xl font-black uppercase italic">
                    Terminer la séance ?
                </h3>
                <div class="flex gap-3">
                    <GlassButton variant="secondary" @click="showFinishModal = false" class="flex-1"
                        >Annuler</GlassButton
                    >
                    <GlassButton
                        variant="primary"
                        id="confirm-finish-button"
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
                    <GlassButton variant="solid" @click="confirmAction" class="flex-1 bg-red-500 text-white"
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
