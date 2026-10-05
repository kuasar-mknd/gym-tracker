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
        // Une séance sans meilleur 1RM n'a pas de point : Math.round(null)
        // vaudrait 0, une chute que personne n'a soulevée (#1956).
        data: reversedData.value.map((d) =>
            d.best_1rm === null || d.best_1rm === undefined ? null : Math.round(d.best_1rm),
        ),
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
        spanGaps: true,
    },
])

const infobulle = {
    accent: 'accent-secondary',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} kg` },
}

/** Les points tracés : Math.min(null, 100) vaut 0, et ramènerait l'axe à zéro. */
const pointsTraces = (context) => context.chart.data.datasets[0].data.filter((valeur) => valeur !== null)

// Add some padding to top and bottom to make the chart look better
const axeY = {
    display: false,
    beginAtZero: false,
    suggestedMin: (context) => Math.min(...pointsTraces(context)) * 0.9,
    suggestedMax: (context) => Math.max(...pointsTraces(context)) * 1.1,
}
</script>

<template>
    <BaseChart
        description="Meilleur 1RM par séance"
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="infobulle"
        :axe-y="axeY"
        :interaction="{ intersect: false, mode: 'index' }"
    />
</template>
