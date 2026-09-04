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

const labels = computed(() => props.data.map((d) => d.date))

const datasets = computed(() => [
    {
        label: 'Volume par séance',
        data: props.data.map((d) => d.volume),
        fill: true,
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.4)) // vivid-violet
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0)) // hot-pink

            return gradient
        },
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-tertiary')) // vivid-violet
            gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink

            return gradient
        },
        borderWidth: 4,
        tension: 0.4,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-tertiary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
    },
])

const infobulle = { accent: 'accent-tertiary', callbacks: { label: (context) => `${context.parsed.y} kg` } }

const marges = { layout: { padding: { left: -10, right: -10, bottom: 0, top: 10 } } }
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        lueur="accent-tertiary"
        :lueur-opacite="0.15"
        :infobulle="infobulle"
        :axe-x="{ ticks: { padding: 10 } }"
        :axe-y="{ display: false, beginAtZero: true }"
        :interaction="{ mode: 'index', intersect: false }"
        :options="marges"
    />
</template>
