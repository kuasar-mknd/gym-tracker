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
    hauteur: { type: String, default: 'h-48' },
})

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Volume (kg)',
        data: props.data.map((item) => item.volume),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-primary'))
            gradient.addColorStop(1, jeton('accent-secondary'))
            return gradient
        },
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        :hauteur="hauteur"
        :infobulle="{ accent: 'accent-primary' }"
        :axe-y="{ ticks: { callback: formatVolumeTick } }"
    />
</template>
