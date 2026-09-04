<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import { formatVolumeTick } from '@/Utils/volumeAxis'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.month))

const datasets = computed(() => [
    {
        label: 'Volume (kg)',
        data: props.data.map((item) => item.volume),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
            gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink
            return gradient
        },
        borderRadius: 4,
        hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => `${Number(context.raw).toLocaleString()} kg` },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48 sm:h-64"
        :infobulle="infobulle"
        :axe-y="{ ticks: { callback: formatVolumeTick } }"
    />
</template>
