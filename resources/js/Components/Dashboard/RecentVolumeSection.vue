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
    <section class="stagger-4 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6">
            <p class="text-text-muted etiquette mb-1">Progression</p>
            <p class="titre-plat">Volume récent</p>
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
