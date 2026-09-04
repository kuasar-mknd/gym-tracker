<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Reverse the data so it reads chronologically from left to right
const reversedData = computed(() => [...props.data].reverse())

const labels = computed(() =>
    reversedData.value.map((d) => {
        const date = new Date(d.started_at)
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'short',
        })
    }),
)

const datasets = computed(() => [
    {
        label: 'Durée (min)',
        data: reversedData.value.map(workoutDurationMinutes),
        fill: true,
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-info', 0.3)) // cyan-pure with opacity
            gradient.addColorStop(1, jetonTransparent('accent-info', 0))

            return gradient
        },
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-info')) // cyan-pure
            gradient.addColorStop(1, jeton('accent-state')) // neon-green

            return gradient
        },
        borderWidth: 4,
        tension: 0.4,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-info'),
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
    },
])

const infobulle = {
    accent: 'accent-info',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} min` },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-y="{ display: false, beginAtZero: true }"
        :interaction="{ mode: 'index', intersect: false }"
    />
</template>
