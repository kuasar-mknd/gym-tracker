<script setup>
/**
 * Les cinq graphiques de l'historique, chacun dans sa carte, et seulement ceux
 * dont la série porte des points : un axe sans rien dessus se lit comme un
 * graphique cassé, pas comme une absence de données.
 */
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'

const WorkoutFrequencyChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutFrequencyChart.vue'))
const WorkoutsPerMonthChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutsPerMonthChart.vue'))
const MonthlyVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/MonthlyVolumeChart.vue'))
const WorkoutDurationChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutDurationChart.vue'))
const VolumePerWorkoutChart = defineAsyncComponent(() => import('@/Components/Stats/VolumePerWorkoutChart.vue'))

defineProps({
    charts: { type: Object, default: () => ({}) },
})
</script>

<template>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <GlassCard v-if="charts?.day_of_week_frequency?.length > 0">
            <div class="mb-4">
                <h3 class="text-text-main text-lg font-bold">Fréquence par Jour</h3>
                <p class="text-text-muted text-xs">Séances selon le jour de la semaine, 6 derniers mois</p>
            </div>
            <div class="h-48 w-full">
                <WorkoutFrequencyChart :data="charts.day_of_week_frequency" />
            </div>
        </GlassCard>

        <GlassCard v-if="charts?.monthly_frequency?.length > 0">
            <div class="mb-4">
                <h3 class="text-text-main text-lg font-bold">Fréquence Mensuelle</h3>
                <p class="text-text-muted text-xs">Séances par mois, 6 derniers mois</p>
            </div>
            <WorkoutsPerMonthChart :data="charts.monthly_frequency" />
        </GlassCard>

        <GlassCard v-if="charts?.monthly_volume?.length > 0">
            <div class="mb-4">
                <h3 class="text-text-main text-lg font-bold">Volume Mensuel</h3>
                <p class="text-text-muted text-xs">Total soulevé par mois (kg)</p>
            </div>
            <MonthlyVolumeChart :data="charts.monthly_volume" />
        </GlassCard>

        <GlassCard v-if="charts?.duration_history?.length > 0">
            <div class="mb-4">
                <h3 class="text-text-main text-lg font-bold">Durée</h3>
                <p class="text-text-muted text-xs">Temps d'entraînement (min)</p>
            </div>
            <WorkoutDurationChart :data="charts.duration_history" />
        </GlassCard>

        <GlassCard v-if="charts?.volume_history?.length > 0">
            <div class="mb-4">
                <h3 class="text-text-main text-lg font-bold">Volume par Séance</h3>
                <p class="text-text-muted text-xs">Volume total soulevé (kg)</p>
            </div>
            <VolumePerWorkoutChart :data="charts.volume_history" />
        </GlassCard>
    </div>
</template>
