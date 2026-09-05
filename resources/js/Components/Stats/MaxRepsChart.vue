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
        label: 'Max Reps',
        data: props.data.map((item) => item.reps),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-info'), // Cyan
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-info', 0.2)) // Cyan with opacity
            gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0)) // Violet transparent
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-info'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('accent-info'),
        pointHoverBorderColor: jeton('surface-card'),
        pointHoverBorderWidth: 2,
    },
])
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="{ accent: 'accent-info' }"
        :axe-y="{ ticks: { precision: 0 } }"
    />
</template>
