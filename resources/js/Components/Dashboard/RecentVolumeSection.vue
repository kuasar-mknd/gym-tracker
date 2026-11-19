<script setup>
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const RecentWorkoutsChart = graphiqueAsynchrone(() => import('@/Components/Stats/RecentWorkoutsChart.vue'))

defineProps({
    recentWorkouts: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent Workouts Volume Chart -->
    <section class="stagger-4 animate-slide-up verre-fin rounded-carte relative overflow-hidden p-5 sm:p-6">
        <div class="relative z-10 mb-5">
            <p class="etiquette">Progression</p>
            <h3 class="titre-plat">Volume récent</h3>
        </div>

        <!-- Recent Workouts Bar Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <RecentWorkoutsChart
                v-if="recentWorkouts && recentWorkouts.some((w) => w.workout_volume > 0)"
                :data="recentWorkouts"
            />
            <GlassEmptyState v-else taille="ligne" icon="bar_chart" title="Pas assez de données de volume" />
        </div>
    </section>
</template>
