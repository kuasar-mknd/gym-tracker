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
    compact: { type: Boolean, default: false },
})

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Poids (kg)',
        data: props.data.map((item) => item.weight),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-primary'),
        backgroundColor: (context) => {
            const { ctx, chartArea } = context.chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 2,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-primary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = { accent: 'accent-primary', callbacks: { label: (context) => `${context.parsed.y} kg` } }
</script>

<template>
    <BaseChart
        description="Évolution du poids corporel"
        type="line"
        :labels="labels"
        :datasets="datasets"
        :hauteur="hauteur"
        :infobulle="infobulle"
        :axe-x="!compact"
        :axe-y="{ grid: { display: false } }"
    />
</template>
