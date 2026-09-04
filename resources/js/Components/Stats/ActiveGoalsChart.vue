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

const labels = computed(() =>
    props.data.map((g) => (g.title && g.title.length > 15 ? g.title.substring(0, 15) + '...' : g.title)),
)

const datasets = computed(() => [
    {
        label: 'Progression (%)',
        data: props.data.map((g) => Math.round(g.progress_pct || 0)),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // electric orange
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0.55))
            return gradient
        },
        borderRadius: 4,
        barPercentage: 0.5,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-primary'),
    },
])

const infobulle = {
    opaque: true,
    callbacks: {
        title: (context) => props.data[context[0].dataIndex].title,
        label: (context) => `${context.parsed.x}% complété`,
    },
}

// L'échelle va de l'objectif intact à l'objectif atteint : sans ce maximum,
// Chart.js se recale sur le meilleur objectif, qui paraît alors terminé.
const axeX = {
    min: 0,
    max: 100,
    grid: { display: true, color: jetonTransparent('surface-card', 0.1) },
    ticks: { callback: (value) => value + '%' },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        index-axis="y"
        :infobulle="infobulle"
        :axe-x="axeX"
        :axe-y="{ grid: { display: false } }"
    />
</template>
