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

const labels = computed(() => props.data.map((d) => d.date))

const datasets = computed(() => [
    {
        label: 'Séries (Sets)',
        data: props.data.map((d) => d.sets),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // Electric orange shade
            gradient.addColorStop(1, jeton('accent-secondary')) // Hot pink shade
            return gradient
        },
        borderRadius: 4,
        barPercentage: 0.5,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])

const infobulle = {
    accent: 'accent-secondary',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} séries` },
}
</script>

<template>
    <BaseChart :labels="labels" :datasets="datasets" hauteur="h-full" :infobulle="infobulle" :axe-y="false" />
</template>
