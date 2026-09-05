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

const labels = computed(() => props.data.map((d) => d.date))

const datasets = computed(() => [
    {
        label: 'Volume (Reps)',
        data: props.data.map((d) => d.reps),
        backgroundColor: (context) => {
            const { ctx, chartArea } = context.chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-info'))
            gradient.addColorStop(1, jetonTransparent('accent-info', 0.55))
            return gradient
        },
        borderRadius: 4,
        barPercentage: 0.5,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])

const infobulle = {
    accent: 'accent-info',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} reps` },
}
</script>

<template>
    <BaseChart :labels="labels" :datasets="datasets" hauteur="h-full" :infobulle="infobulle" />
</template>
