<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { accord, entier, poids } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// We want the chart to go from 50% up to 100%
const donneesTriees = computed(() => [...props.data].sort((a, b) => a.percent - b.percent))

const labels = computed(() => donneesTriees.value.map((d) => `${d.percent}%`))

const datasets = computed(() => [
    {
        label: 'Poids Estimé (kg)',
        data: donneesTriees.value.map((d) => d.value),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
            gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink

            return gradient
        },
        borderRadius: 8,
        barPercentage: 0.6,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
    },
])

/**
 * Les répétitions estimées, que la page envoie en texte : un entier (« 20 »)
 * ou un plancher (« 25+ »). Le nom s'accorde au nombre, et le « + » reste
 * collé au nombre : « 1 rep », « 25+ reps » (#1980).
 */
const repetitions = (reps) => {
    const [, valeur, plancher] = /^(\d+)(\+?)$/.exec(String(reps).trim()) ?? []

    return valeur === undefined ? String(reps) : `${entier(valeur)}${plancher} ${accord(valeur, 'rep', 'reps')}`
}

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: {
        label: (context) => {
            // Try to find the est reps for this data point
            const dataPoint = [...props.data].find((d) => `${d.percent}%` === context.label)
            const repsText = dataPoint && dataPoint.reps !== '-' ? ` (${repetitions(dataPoint.reps)})` : ''
            return `${poids(context.parsed.y)}${repsText}`
        },
    },
}
</script>

<template>
    <BaseChart
        description="Charge pour chaque pourcentage du 1RM"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
    />
</template>
