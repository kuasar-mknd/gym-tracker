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
    label: {
        type: String,
        default: 'Measurement',
    },
    unit: {
        type: String,
        default: 'cm',
    },
})

const labels = computed(() => props.data.map((item) => etiquetteDeDate(item.measured_at)))

const datasets = computed(() => [
    {
        label: props.label,
        data: props.data.map((item) => item.value),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-tertiary')) // Purple
            gradient.addColorStop(1, jeton('accent-secondary')) // Pink
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: jeton('accent-tertiary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-tertiary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = {
    accent: 'accent-tertiary',
    callbacks: { label: (context) => `${context.parsed.y} ${props.unit}` },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        :infobulle="infobulle"
        :axe-y="{ grid: { display: false } }"
    />
</template>
