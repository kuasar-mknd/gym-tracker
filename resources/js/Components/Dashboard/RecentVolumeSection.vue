<script setup>
import { defineAsyncComponent } from 'vue'

const RecentWorkoutsChart = defineAsyncComponent(() => import('@/Components/Stats/RecentWorkoutsChart.vue'))

defineProps({
    recentWorkouts: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent Workouts Volume Chart -->
    <section
        class="animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.19s"
    >
        <div class="relative z-10 mb-6">
            <h3 class="text-accent-primary-deep mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Progression</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic">Volume Récent</p>
        </div>

        <!-- Recent Workouts Bar Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <RecentWorkoutsChart
                v-if="recentWorkouts && recentWorkouts.some((w) => w.workout_volume > 0)"
                :data="recentWorkouts"
            />
            <div v-else class="text-text-muted flex h-full items-center justify-center">
                <p class="text-sm">Pas assez de données de volume</p>
            </div>
        </div>
    </section>
</template>
