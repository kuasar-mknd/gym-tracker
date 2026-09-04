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
    props.data.map((item) =>
        new Date(item.created_at).toLocaleDateString(undefined, {
            day: 'numeric',
            month: 'short',
        }),
    ),
)

const datasets = computed(() => [
    {
        label: 'Score Wilks',
        data: props.data.map((item) => parseFloat(item.score).toFixed(2)),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-primary')) // Electric Orange
            gradient.addColorStop(1, jeton('accent-secondary')) // Hot Pink
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
        pointRadius: 3,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-primary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: { label: (context) => `Score: ${context.parsed.y}` },
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
        :axe-x="{ ticks: { maxTicksLimit: 6 } }"
    />
</template>
