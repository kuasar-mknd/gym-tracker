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
        label: 'Durée (min)',
        data: props.data.map((item) => item.duration),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-info')) // Cyan-500
            gradient.addColorStop(1, jetonTransparent('accent-info', 0.55)) // Blue-500
            return gradient
        },
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-info'), // Sky-500
    },
])

const infobulle = {
    accent: 'accent-info',
    callbacks: {
        label: (context) => `${context.parsed.y} min`,
        afterLabel: (context) => props.data[context.dataIndex]?.name || '',
    },
}
</script>

<template>
    <BaseChart :labels="labels" :datasets="datasets" hauteur="h-48" :infobulle="infobulle" />
</template>
