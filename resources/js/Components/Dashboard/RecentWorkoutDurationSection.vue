<script setup>
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const DashboardDurationChart = graphiqueAsynchrone(() => import('@/Components/Stats/DashboardDurationChart.vue'))

defineProps({
    recentWorkouts: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent Workouts Duration Chart -->
    <section class="stagger-4 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6">
            <p class="text-text-muted etiquette mb-1">Endurance</p>
            <p class="titre-plat">Durée récente</p>
        </div>

        <!-- Recent Workouts Line Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <DashboardDurationChart
                v-if="recentWorkouts && recentWorkouts.some((w) => w.ended_at)"
                :data="recentWorkouts"
            />
            <GlassEmptyState v-else taille="ligne" icon="timer" title="Pas assez de données de durée" />
        </div>
    </section>
</template>
