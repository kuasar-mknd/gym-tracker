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

// We only want completed fasts with an end_time, sorted chronologically by start_time
const jeunesTermines = computed(() =>
    [...props.data].filter((fast) => fast.end_time).sort((a, b) => new Date(a.start_time) - new Date(b.start_time)),
)

const labels = computed(() =>
    jeunesTermines.value.map((fast) => {
        const date = new Date(fast.start_time)
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
    }),
)

const datasets = computed(() => [
    {
        label: 'Durée (heures)',
        data: jeunesTermines.value.map((fast) => {
            const start = new Date(fast.start_time)
            const end = new Date(fast.end_time)
            const diffMs = end - start
            return (diffMs / (1000 * 60 * 60)).toFixed(1)
        }),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            // Liquid Glass gradient: Orange to Red/Pink
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // Orange
            gradient.addColorStop(1, jeton('accent-danger')) // Red
            return gradient
        },
        borderRadius: 8,
        borderSkipped: false,
        barThickness: 16,
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => `${context.parsed.y} h` },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        lueur="accent-danger"
        :infobulle="infobulle"
        :axe-y="{ ticks: { stepSize: 4 } }"
    />
</template>
