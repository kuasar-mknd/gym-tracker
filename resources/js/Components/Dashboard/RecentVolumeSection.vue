<script setup>
import { defineAsyncComponent } from 'vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const RecentWorkoutsChart = defineAsyncComponent(() => import('@/Components/Stats/RecentWorkoutsChart.vue'))

defineProps({
    recentWorkouts: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent Workouts Volume Chart -->
    <section
        class="stagger-4 animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
    >
        <div class="relative z-10 mb-6">
            <h3 class="sur-titre text-accent-primary-deep mb-1">Progression</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic">Volume Récent</p>
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
