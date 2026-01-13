<!--
  Workouts/Show.vue

  This component displays the details of a specific workout.
  It allows the user to:
  - View exercises and sets in the workout.
  - Add new exercises to the workout.
  - Add, update, and remove sets for each exercise.
  - Remove exercises (workout lines) from the workout.
-->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { ref } from 'vue'

/**
 * Component Props
 *
 * @property {Object} workout - The workout object containing details, lines, and sets.
 * @property {Array} exercises - List of all available exercises for the "Add Exercise" modal.
 */
const props = defineProps({
    workout: Object,
    exercises: Array,
})

// State for toggling the "Add Exercise" modal
const showAddExercise = ref(false)
// State for the exercise search query
const searchQuery = ref('')

const addExerciseForm = useForm({
    exercise_id: '',
})

/**
 * Adds an exercise to the current workout.
 *
 * @param {Number} exerciseId - The ID of the exercise to add.
 */
const addExercise = (exerciseId) => {
    addExerciseForm.exercise_id = exerciseId
    addExerciseForm.post(route('workout-lines.store', props.workout.id), {
        onSuccess: () => {
            showAddExercise.value = false
        },
    })
}

/**
 * Removes a workout line (exercise) from the workout.
 *
 * @param {Number} lineId - The ID of the workout line to remove.
 */
const removeLine = (lineId) => {
    if (confirm('Supprimer cet exercice de la séance ?')) {
        router.delete(route('workout-lines.destroy', lineId))
    }
}

/**
 * Adds a new set to a workout line.
 * Pre-fills the weight and reps with values from the last set if available.
 *
 * @param {Number} lineId - The ID of the workout line.
 */
const addSet = (lineId) => {
    const lastSet = props.workout.workout_lines.find((l) => l.id === lineId).sets.at(-1)

    router.post(route('sets.store', lineId), {
        weight: lastSet ? lastSet.weight : 0,
        reps: lastSet ? lastSet.reps : 0,
    })
}

/**
 * Updates a specific field of a set.
 *
 * @param {Object} set - The set object to update.
 * @param {String} field - The field name (e.g., 'weight', 'reps').
 * @param {String|Number} value - The new value.
 */
const updateSet = (set, field, value) => {
    router.patch(
        route('sets.update', set.id),
        {
            [field]: value,
        },
        {
            preserveScroll: true,
            only: ['workout'],
        },
    )
}

/**
 * Removes a set from a workout line.
 *
 * @param {Number} setId - The ID of the set to remove.
 */
const removeSet = (setId) => {
    router.delete(route('sets.destroy', setId))
}

/**
 * Filters the list of exercises based on the search query.
 *
 * @returns {Array} The filtered list of exercises.
 */
const filteredExercises = () => {
    if (!searchQuery.value) return props.exercises
    return props.exercises.filter((e) => e.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
}
</script>

<template>
    <Head :title="workout.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {{ workout.name }}
                </h2>
                <PrimaryButton @click="showAddExercise = true">Ajouter un exercice</PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <!-- Session Details -->
                <div
                    v-for="line in workout.workout_lines"
                    :key="line.id"
                    class="overflow-hidden border border-gray-200 bg-white shadow-sm sm:rounded-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-xl font-bold dark:text-white">{{ line.exercise.name }}</h3>
                            <button @click="removeLine(line.id)" class="text-sm text-red-500 hover:text-red-700">
                                Supprimer
                            </button>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="(set, index) in line.sets"
                                :key="set.id"
                                class="flex items-center gap-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-900"
                            >
                                <span class="w-6 font-bold text-gray-500">{{ index + 1 }}</span>

                                <div class="grid flex-1 grid-cols-2 gap-4">
                                    <div class="flex items-center gap-2">
                                        <TextInput
                                            type="number"
                                            class="w-20 text-center"
                                            :model-value="set.weight"
                                            @change="(e) => updateSet(set, 'weight', e.target.value)"
                                        />
                                        <span class="text-sm text-gray-500">kg</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <TextInput
                                            type="number"
                                            class="w-20 text-center"
                                            :model-value="set.reps"
                                            @change="(e) => updateSet(set, 'reps', e.target.value)"
                                        />
                                        <span class="text-sm text-gray-500">reps</span>
                                    </div>
                                </div>

                                <button @click="removeSet(set.id)" class="text-gray-400 hover:text-red-500">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button
                            @click="addSet(line.id)"
                            class="mt-4 w-full rounded-lg bg-gray-100 py-2 text-sm font-medium transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        >
                            + Ajouter une série
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="workout.workout_lines.length === 0"
                    class="rounded-lg bg-white py-20 text-center shadow-sm dark:bg-gray-800"
                >
                    <p class="mb-4 text-gray-500">Votre séance est vide.</p>
                    <PrimaryButton @click="showAddExercise = true">Commencer par ajouter un exercice</PrimaryButton>
                </div>
            </div>
        </div>

        <!-- Add Exercise Modal (Simple version for now) -->
        <div
            v-if="showAddExercise"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
        >
            <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 p-6 dark:border-gray-700">
                    <h3 class="text-xl font-bold dark:text-white">Choisir un exercice</h3>
                    <button @click="showAddExercise = false" class="text-gray-400 hover:text-gray-600">Fermer</button>
                </div>
                <div class="p-6">
                    <TextInput
                        v-model="searchQuery"
                        class="mb-4 w-full"
                        placeholder="Rechercher un exercice..."
                        autofocus
                    />
                    <div class="max-h-96 space-y-2 overflow-y-auto">
                        <button
                            v-for="exercise in filteredExercises()"
                            :key="exercise.id"
                            @click="addExercise(exercise.id)"
                            class="group flex w-full items-center justify-between rounded-xl border border-transparent p-4 text-left transition-all hover:border-gray-200 hover:bg-gray-50 dark:hover:border-gray-700 dark:hover:bg-gray-900"
                        >
                            <div>
                                <div class="font-bold dark:text-white">{{ exercise.name }}</div>
                                <div class="text-sm text-gray-500">{{ exercise.category }}</div>
                            </div>
                            <span class="text-pacamara-accent opacity-0 transition-opacity group-hover:opacity-100"
                                >+</span
                            >
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
