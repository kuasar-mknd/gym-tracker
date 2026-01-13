<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps({
    workouts: Array,
    exercises: Array,
})

const form = useForm({})

const createWorkout = () => {
    form.post(route('workouts.store'))
}
</script>

<template>
    <Head title="Séances" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Mes Séances</h2>
                <PrimaryButton @click="createWorkout">Nouvelle Séance</PrimaryButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Exercises Section -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="mb-4 text-lg font-medium">Exercices Disponibles</h3>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div
                                v-for="exercise in exercises"
                                :key="exercise.id"
                                class="rounded-lg border border-gray-200 bg-gray-50 p-4 transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                            >
                                <div class="font-bold">{{ exercise.name }}</div>
                                <div class="text-sm text-gray-500">{{ exercise.category }} ({{ exercise.type }})</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Last Workouts -->
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="mb-4 text-lg font-medium">Dernières Séances</h3>
                        <div v-if="workouts.length === 0" class="py-8 text-center text-gray-500">
                            Aucune séance enregistrée. Cliquez sur "Nouvelle Séance" pour commencer.
                        </div>
                        <div v-else class="space-y-4">
                            <div
                                v-for="workout in workouts"
                                :key="workout.id"
                                class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                            >
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="text-lg font-bold">{{ workout.name || 'Séance sans nom' }}</div>
                                        <div class="text-sm text-gray-500">
                                            Le
                                            {{
                                                new Date(workout.started_at).toLocaleDateString('fr-FR', {
                                                    weekday: 'long',
                                                    day: 'numeric',
                                                    month: 'long',
                                                })
                                            }}
                                        </div>
                                    </div>
                                    <div
                                        class="rounded bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-300"
                                    >
                                        {{ workout.workout_lines.length }} exercices
                                    </div>
                                </div>

                                <div v-if="workout.workout_lines.length > 0" class="mt-4 space-y-2">
                                    <div v-for="line in workout.workout_lines" :key="line.id" class="text-sm">
                                        <span class="font-semibold">{{ line.exercise.name }}</span
                                        >: {{ line.sets.length }} séries
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
