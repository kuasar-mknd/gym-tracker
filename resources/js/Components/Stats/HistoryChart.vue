<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Expecting history array to have formatted_date and best_1rm
// The history array is typically sorted descending (newest first).
// Let's reverse it so time flows left to right.
const reversedData = computed(() => [...props.data].reverse())

const labels = computed(() => reversedData.value.map((d) => d.formatted_date))

const datasets = computed(() => [
    {
        label: 'Meilleur 1RM (kg)',
        data: reversedData.value.map((d) => Math.round(d.best_1rm)),
        borderColor: jeton('accent-secondary'), // hot-pink
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jetonTransparent('accent-secondary', 0.05))
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0.4))

            return gradient
        },
        borderWidth: 3,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-secondary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
        fill: true,
        tension: 0.4, // Smooth curve
    },
])

const infobulle = {
    accent: 'accent-secondary',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} kg` },
}

// Add some padding to top and bottom to make the chart look better
const axeY = {
    display: false,
    beginAtZero: false,
    suggestedMin: (context) => {
        const values = context.chart.data.datasets[0].data
        return Math.min(...values) * 0.9
    },
    suggestedMax: (context) => {
        const values = context.chart.data.datasets[0].data
        return Math.max(...values) * 1.1
    },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="infobulle"
        :axe-y="axeY"
        :interaction="{ intersect: false, mode: 'index' }"
    />
</template>
