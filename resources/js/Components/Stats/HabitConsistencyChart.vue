<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import { etiquetteDeDate } from '@/Utils/date'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => etiquetteDeDate(item.date)))

const datasets = computed(() => [
    {
        label: 'Habits Completed',
        data: props.data.map((item) => item.count),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-state')) // Emerald 400
            gradient.addColorStop(1, jeton('accent-info')) // Teal 400
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-state', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-state', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 0, // Hide points for cleaner look, show on hover
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-state'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = { accent: 'accent-state', callbacks: { label: (context) => `${context.parsed.y} Complétés` } }
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-y="{ ticks: { stepSize: 1, precision: 0 }, grid: { display: false }, beginAtZero: true }"
        :interaction="{ mode: 'index', intersect: false }"
    />
</template>
