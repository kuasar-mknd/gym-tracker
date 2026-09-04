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

// Clone and reverse to have oldest first
const sortedData = computed(() => [...props.data].reverse())

const labels = computed(() =>
    sortedData.value.map((item) => {
        return new Date(item.created_at).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        })
    }),
)

const datasets = computed(() => [
    {
        label: 'Cible (kcal)',
        data: sortedData.value.map((item) => item.target_calories),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
            gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-primary'),
        pointHoverBorderWidth: 3,
    },
    {
        label: 'TDEE (kcal)',
        data: sortedData.value.map((item) => item.tdee),
        fill: false,
        tension: 0.4,
        borderColor: jeton('text-muted'), // Slate-400
        borderDash: [5, 5],
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    displayColors: true,
    boxPadding: 4,
    titleFont: { family: 'Space Grotesk', weight: 'bold' },
    bodyFont: { family: 'Space Grotesk' },
    callbacks: { label: (context) => `${context.dataset.label}: ${context.parsed.y}` },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        lueur="accent-primary"
        :legende="true"
        :infobulle="infobulle"
        :axe-x="{ ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 6 } }"
        :axe-y="{ grid: { borderDash: [4, 4] } }"
        :interaction="{ mode: 'index', intersect: false }"
    />
</template>
