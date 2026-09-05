<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { etiquetteDeDate } from '@/Utils/date'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Reverse the data so it reads chronologically from left to right
const donneesChronologiques = computed(() => [...props.data].reverse())

const labels = computed(() =>
    donneesChronologiques.value.map((d) => {
        const date = new Date(d.started_at)
        return etiquetteDeDate(date)
    }),
)

const datasets = computed(() => [
    {
        label: 'Volume (kg)',
        data: donneesChronologiques.value.map((d) => d.workout_volume || 0),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
            gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink

            return gradient
        },
        borderRadius: 8,
        barPercentage: 0.6,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} kg` },
}
</script>

<template>
    <BaseChart :labels="labels" :datasets="datasets" hauteur="h-48" :infobulle="infobulle" :axe-y="false" />
</template>
