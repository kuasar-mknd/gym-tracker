<!--
  Workouts/Show.vue - Active Workout Page
  Mobile-first design with glass cards for exercise logging
-->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

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

const showAddExercise = ref(false)
const searchQuery = ref('')
const showCreateForm = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))

// Confirmation modal state
const showConfirmModal = ref(false)
const confirmAction = ref(null)
const confirmMessage = ref('')

const addExerciseForm = useForm({
    exercise_id: '',
})

const createExerciseForm = useForm({
    name: '',
    type: 'strength',
    category: '',
})

const addExercise = (exerciseId) => {
    addExerciseForm.exercise_id = exerciseId
    addExerciseForm.post(route('workout-lines.store', props.workout.id), {
        onSuccess: () => {
            showAddExercise.value = false
            searchQuery.value = ''
        },
    })
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
                route('workout-lines.store', props.workout.id),
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
    confirmMessage.value = 'Supprimer cet exercice de la séance ?'
    confirmAction.value = () => {
        router.delete(route('workout-lines.destroy', lineId))
        showConfirmModal.value = false
    }
    showConfirmModal.value = true
}

const cancelConfirm = () => {
    showConfirmModal.value = false
    confirmAction.value = null
}

const addSet = (lineId) => {
    const line = props.workout.workout_lines.find((l) => l.id === lineId)
    const lastSet = line.sets.at(-1)

    router.post(route('sets.store', lineId), {
        weight: lastSet ? lastSet.weight : 0,
        reps: lastSet ? lastSet.reps : 10,
    })
}

const updateSet = (set, field, value) => {
    router.patch(route('sets.update', set.id), { [field]: value }, { preserveScroll: true, only: ['workout'] })
}

const removeSet = (setId) => {
    router.delete(route('sets.destroy', setId), { preserveScroll: true })
}

const filteredExercises = computed(() => {
    const exercises = localExercises.value.filter((e) => e && e.id)
    if (!searchQuery.value) return exercises
    return exercises.filter((e) => e.name?.toLowerCase().includes(searchQuery.value.toLowerCase()))
})

const hasNoResults = computed(() => {
    return searchQuery.value && filteredExercises.value.length === 0
})
</script>

<template>
    <Head :title="workout.name || 'Séance'" />

    <AuthenticatedLayout :page-title="workout.name || 'Séance en cours'" show-back back-route="workouts.index">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddExercise = true">
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">{{ workout.name || 'Séance' }}</h2>
                <GlassButton @click="showAddExercise = true">
                    <svg
                        class="mr-2 h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter un exercice
                </GlassButton>
            </div>
        </template>

        <div class="space-y-4">
            <!-- Exercise Cards -->
            <GlassCard v-for="line in workout.workout_lines" :key="line.id" class="animate-slide-up">
                <!-- Exercise Header -->
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">{{ line.exercise.name }}</h3>
                        <p class="text-sm text-white/50">{{ line.exercise.category }}</p>
                    </div>
                    <button
                        @click="removeLine(line.id)"
                        class="rounded-lg p-2 text-white/40 transition hover:bg-white/10 hover:text-red-400"
                    >
                        <svg
                            class="h-5 w-5"
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
                    </button>
                </div>

                <!-- Sets List -->
                <div class="space-y-2">
                    <div
                        v-for="(set, index) in line.sets"
                        :key="set.id"
                        class="flex items-center gap-3 rounded-xl bg-white/5 p-3"
                    >
                        <!-- Set Number -->
                        <div
                            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-accent-primary/20 text-sm font-bold text-accent-primary"
                        >
                            {{ index + 1 }}
                        </div>

                        <!-- Weight Input -->
                        <div class="flex flex-1 items-center gap-2">
                            <input
                                type="number"
                                :value="set.weight"
                                @change="(e) => updateSet(set, 'weight', e.target.value)"
                                class="w-16 rounded-lg bg-white/10 px-2 py-2 text-center text-white outline-none focus:ring-2 focus:ring-accent-primary"
                                inputmode="decimal"
                            />
                            <span class="text-sm text-white/50">kg</span>
                        </div>

                        <!-- Reps Input -->
                        <div class="flex flex-1 items-center gap-2">
                            <input
                                type="number"
                                :value="set.reps"
                                @change="(e) => updateSet(set, 'reps', e.target.value)"
                                class="w-16 rounded-lg bg-white/10 px-2 py-2 text-center text-white outline-none focus:ring-2 focus:ring-accent-primary"
                                inputmode="numeric"
                            />
                            <span class="text-sm text-white/50">reps</span>
                        </div>

                        <!-- Delete Set -->
                        <button
                            @click="removeSet(set.id)"
                            class="rounded-lg p-2 text-white/30 transition hover:text-red-400"
                        >
                            <svg
                                class="h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
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
                    @click="addSet(line.id)"
                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-white/5 py-3 text-sm font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                    <svg
                        class="h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter une série
                </button>
            </GlassCard>

            <!-- Empty State -->
            <GlassCard v-if="workout.workout_lines.length === 0" class="animate-slide-up">
                <div class="py-12 text-center">
                    <div class="mb-3 text-5xl">🎯</div>
                    <h3 class="text-lg font-semibold text-white">Séance vide</h3>
                    <p class="mt-1 text-white/60">Ajoute ton premier exercice</p>
                    <GlassButton variant="primary" class="mt-4" @click="showAddExercise = true">
                        Ajouter un exercice
                    </GlassButton>
                </div>
            </GlassCard>
        </div>

        <!-- FAB -->
        <button @click="showAddExercise = true" class="glass-fab sm:hidden">
            <svg
                class="h-6 w-6 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
        </button>

        <!-- Add Exercise Modal -->
        <Teleport to="body">
            <div v-if="showAddExercise" class="glass-overlay animate-fade-in" @click.self="showAddExercise = false">
                <div
                    class="fixed inset-x-4 bottom-4 top-auto max-h-[80vh] sm:inset-auto sm:left-1/2 sm:top-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2"
                >
                    <div class="glass-modal animate-slide-up overflow-hidden">
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between border-b border-glass-border p-4">
                            <h3 class="text-lg font-bold text-white">
                                {{ showCreateForm ? 'Nouvel exercice' : 'Choisir un exercice' }}
                            </h3>
                            <button
                                @click="closeModal"
                                class="rounded-lg p-2 text-white/50 hover:bg-white/10 hover:text-white"
                            >
                                <svg
                                    class="h-5 w-5"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
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
                                        <label class="mb-1.5 block text-sm font-medium text-white/70">Type</label>
                                        <select v-model="createExerciseForm.type" class="glass-input w-full text-sm">
                                            <option v-for="t in types" :key="t.value" :value="t.value">
                                                {{ t.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-white/70">Catégorie</label>
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
                                    <p class="mb-3 text-white/60">Aucun exercice trouvé pour "{{ searchQuery }}"</p>
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
                                        class="flex w-full items-center gap-3 rounded-xl border-2 border-dashed border-white/20 p-4 text-left transition hover:border-white/40 hover:bg-white/5"
                                    >
                                        <div
                                            class="flex h-10 w-10 items-center justify-center rounded-lg bg-accent-primary/20"
                                        >
                                            <svg
                                                class="h-5 w-5 text-accent-primary"
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
                                            <div class="font-semibold text-white">Créer un nouvel exercice</div>
                                            <div class="text-sm text-white/50">Si tu ne trouves pas ton exercice</div>
                                        </div>
                                    </button>

                                    <!-- Existing Exercises -->
                                    <button
                                        v-for="exercise in filteredExercises"
                                        :key="exercise.id"
                                        @click="addExercise(exercise.id)"
                                        :disabled="addExerciseForm.processing"
                                        class="flex w-full items-center justify-between rounded-xl p-4 text-left transition hover:bg-white/10 disabled:opacity-50"
                                    >
                                        <div>
                                            <div class="font-semibold text-white">{{ exercise.name }}</div>
                                            <div class="text-sm text-white/50">{{ exercise.category }}</div>
                                        </div>
                                        <span
                                            class="text-2xl text-accent-primary opacity-0 transition group-hover:opacity-100"
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
                    class="fixed inset-x-4 bottom-auto top-1/2 -translate-y-1/2 sm:inset-auto sm:left-1/2 sm:w-full sm:max-w-sm sm:-translate-x-1/2"
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
                            <h3 class="mb-2 text-lg font-bold text-white">Confirmer la suppression</h3>
                            <p class="mb-6 text-white/60">{{ confirmMessage }}</p>
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
    </AuthenticatedLayout>
</template>
