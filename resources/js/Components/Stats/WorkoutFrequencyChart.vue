<script setup>
import { jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((d) => d.day))

const datasets = computed(() => [
    {
        label: 'Séances',
        data: props.data.map((d) => d.count),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jetonTransparent('accent-info', 0.6)) // Cyan
            gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0.8)) // Violet
            return gradient
        },
        borderRadius: 6,
        barPercentage: 0.6,
        hoverBackgroundColor: jetonTransparent('accent-info', 1),
        borderWidth: 1,
        borderColor: jetonTransparent('surface-card', 0.2),
    },
])

const infobulle = {
    accent: 'accent-info',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} séances` },
}
</script>

<template>
    <BaseChart :labels="labels" :datasets="datasets" hauteur="h-full" lueur="accent-tertiary" :infobulle="infobulle" />
</template>
