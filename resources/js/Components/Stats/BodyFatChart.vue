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
        label: 'Masse Grasse (%)',
        data: props.data.map((item) => item.body_fat),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-secondary'), // Magenta
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-secondary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-secondary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 2,
        pointBackgroundColor: jeton('accent-secondary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
    },
])

const infobulle = { accent: 'accent-secondary', callbacks: { label: (context) => `${context.parsed.y} %` } }
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-32"
        lueur="accent-secondary"
        :infobulle="infobulle"
        :axe-x="false"
        :axe-y="{ grid: { display: false } }"
    />
</template>
