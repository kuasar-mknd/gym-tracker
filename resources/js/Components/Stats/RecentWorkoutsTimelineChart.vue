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

// We reverse the data to show chronological order from left to right
const reversedData = computed(() => [...props.data].reverse())

const labels = computed(() => reversedData.value.map((workout) => etiquetteDeDate(workout.started_at)))

const datasets = computed(() => [
    {
        label: 'Durée (min)',
        data: reversedData.value.map(workoutDurationMinutes),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-tertiary')) // vivid-violet
            gradient.addColorStop(1, jeton('accent-primary')) // electric-orange
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 4,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-primary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: {
        label: (context) => `${context.parsed.y} minutes`,
        title: (context) => {
            // Reversed again because the chart data is reversed
            const workout = [...props.data].reverse()[context[0].dataIndex]
            return workout.name || 'Séance'
        },
    },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-40"
        lueur="accent-tertiary"
        :infobulle="infobulle"
        :axe-y="{ display: false, beginAtZero: true }"
    />
</template>
