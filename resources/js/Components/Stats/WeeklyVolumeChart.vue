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

const labels = computed(() => props.data.map((d) => d.day_label))

const datasets = computed(() => [
    {
        label: 'Volume',
        data: props.data.map((d) => d.volume),
        fill: true,
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0))

            return gradient
        },
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-primary'))
            gradient.addColorStop(0.5, jeton('accent-secondary'))
            gradient.addColorStop(1, jeton('accent-tertiary'))

            return gradient
        },
        borderWidth: 4,
        tension: 0.4,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-secondary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
    },
])

const infobulle = { accent: 'accent-primary', callbacks: { label: (context) => `${context.parsed.y} kg` } }

const marges = { layout: { padding: { left: -10, right: -10, bottom: 0, top: 10 } } }
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { padding: 10 } }"
        :axe-y="{ display: false, beginAtZero: true }"
        :interaction="{ mode: 'index', intersect: false }"
        :options="marges"
    />
</template>
