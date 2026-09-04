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
        label: 'Charge Moyenne (kg)',
        data: props.data.map((item) => item.weight),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-tertiary'), // Indigo
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.2)) // Indigo with opacity
            gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0)) // Transparent
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-tertiary'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('accent-tertiary'),
        pointHoverBorderColor: jeton('surface-card'),
        pointHoverBorderWidth: 2,
    },
])

const infobulle = { accent: 'accent-tertiary', callbacks: { label: (context) => `${context.parsed.y} kg` } }
</script>

<template>
    <BaseChart type="line" :labels="labels" :datasets="datasets" hauteur="h-full" :infobulle="infobulle" />
</template>
