<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
    hauteur: { type: String, default: 'h-40' },
})

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Masse Grasse (%)',
        data: props.data.map((item) => item.body_fat),
        fill: true,
        tension: 0.4,
        borderColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            // Gradient matching a neon 'Liquid Glass' theme: Violet to Hot Pink
            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
            gradient.addColorStop(0, jeton('accent-tertiary')) // Violet
            gradient.addColorStop(1, jeton('accent-secondary')) // Hot Pink
            return gradient
        },
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-secondary', 0.25)) // Translucent pink
            gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0.05)) // Fading to faint violet
            return gradient
        },
        borderWidth: 3,
        pointRadius: 2,
        pointBackgroundColor: jeton('accent-secondary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-tertiary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = { accent: 'accent-secondary', callbacks: { label: (context) => `${context.parsed.y} %` } }
</script>

<template>
    <BaseChart
        type="line"
        :labels="labels"
        :datasets="datasets"
        :hauteur="hauteur"
        lueur="accent-secondary"
        :infobulle="infobulle"
        :axe-y="{ grid: { display: false } }"
    />
</template>
