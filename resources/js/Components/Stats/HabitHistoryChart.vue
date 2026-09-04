<script setup>
import { jeton } from '@/Utils/couleurs'
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
        label: 'Habitudes complétées',
        data: props.data.map((item) => item.count),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-tertiary')) // Violet
            gradient.addColorStop(1, jeton('accent-secondary')) // Pink
            return gradient
        },
        borderRadius: 4,
        barThickness: 'flex',
        maxBarThickness: 20,
    },
])

const infobulle = {
    accent: 'accent-tertiary',
    callbacks: { label: (context) => `${context.parsed.y} habitudes` },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } }"
        :axe-y="{ ticks: { stepSize: 1 } }"
    />
</template>
