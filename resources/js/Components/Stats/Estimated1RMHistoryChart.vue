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
        label: '1RM Estimé (kg)',
        data: props.data.map((item) => item.weight),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-secondary'), // Pink-500
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-secondary', 0.2)) // Pink with opacity
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0)) // Transparent
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-secondary'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('accent-secondary'),
        pointHoverBorderColor: jeton('surface-card'),
        pointHoverBorderWidth: 2,
    },
])

const infobulle = { accent: 'accent-secondary', callbacks: { label: (context) => `${context.parsed.y} kg` } }
</script>

<template>
    <BaseChart type="line" :labels="labels" :datasets="datasets" hauteur="h-full" :infobulle="infobulle" />
</template>
