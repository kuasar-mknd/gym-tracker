<script setup>
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const RecentWorkoutsExercisesChart = graphiqueAsynchrone(
    () => import('@/Components/Stats/RecentWorkoutsExercisesChart.vue'),
)

defineProps({
    recentWorkouts: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent Workouts Exercises Chart -->
    <section class="stagger-4 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6">
            <p class="text-text-muted etiquette mb-1">Complexité</p>
            <h3 class="titre-plat">Exercices / séance</h3>
        </div>

        <!-- Recent Workouts Bar Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <RecentWorkoutsExercisesChart
                v-if="recentWorkouts && recentWorkouts.some((w) => w.workout_lines_count > 0)"
                :data="recentWorkouts"
            />
            <GlassEmptyState v-else taille="ligne" icon="exercise" title="Pas assez de données d'exercices" />
        </div>
    </section>
</template>
