<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'
import BaseChart from './BaseChart.vue'
import { etiquetteDeDate } from '@/Utils/date'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Reverse the data to show oldest to newest (left to right)
const seances = computed(() => [...props.data].reverse())

const labels = computed(() =>
    seances.value.map((d) => {
        const date = new Date(d.started_at)
        return etiquetteDeDate(date)
    }),
)

const datasets = computed(() => [
    {
        type: 'line',
        label: 'Durée (min)',
        data: seances.value.map(workoutDurationMinutes),
        borderColor: jeton('accent-secondary'), // hot-pink
        borderWidth: 3,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-secondary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        tension: 0.4,
        yAxisID: 'y1',
    },
    {
        type: 'bar',
        label: 'Volume (kg)',
        data: seances.value.map((d) => d.workout_volume || 0),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.4)) // electric-orange with opacity
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0.8)) // hot-pink with opacity

            return gradient
        },
        borderRadius: 8,
        barPercentage: 0.6,
        yAxisID: 'y',
    },
])

const infobulle = {
    opaque: true,
    displayColors: true,
    callbacks: {
        label: (context) => {
            const label = context.dataset.label || ''
            if (context.datasetIndex === 0) {
                return `${label}: ${context.parsed.y} min`
            }
            return `${label}: ${context.parsed.y} kg`
        },
    },
}

const axeY1 = { display: false, position: 'right', grid: { drawOnChartArea: false } }
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        :legende="{ position: 'top' }"
        :infobulle="infobulle"
        :axe-y1="axeY1"
    />
</template>
