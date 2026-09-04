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

// Sort data by date ascending
const sortedData = computed(() => {
    return [...props.data].sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
})

const labels = computed(() => sortedData.value.map((item) => new Date(item.created_at).toLocaleDateString()))

const datasets = computed(() => [
    {
        label: 'Score Wilks',
        data: sortedData.value.map((item) => parseFloat(item.score)),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-primary')) // Orange
            gradient.addColorStop(1, jeton('accent-secondary')) // Pink
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 4,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-secondary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => `Score: ${parseFloat(context.parsed.y).toFixed(2)}` },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        lueur="accent-primary"
        :infobulle="infobulle"
        :axe-x="false"
        :axe-y="{ grid: { borderDash: [4, 4] } }"
    />
</template>
