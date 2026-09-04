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

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Doses prises',
        data: props.data.map((item) => item.count),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-info')) // Cyan
            gradient.addColorStop(1, jetonTransparent('accent-info', 0.55)) // Blue
            return gradient
        },
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-info'),
    },
])

const infobulle = {
    accent: 'accent-info',
    callbacks: { label: (context) => `${context.raw} doses` },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } }"
        :axe-y="{ ticks: { stepSize: 1, precision: 0 } }"
    />
</template>
