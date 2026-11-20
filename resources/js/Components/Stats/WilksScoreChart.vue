<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { etiquetteDeDate } from '@/Utils/date'
import { nombre } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Sort data by date ascending
const sortedData = computed(() => {
    return [...props.data].sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
})

const labels = computed(() => sortedData.value.map((item) => etiquetteDeDate(item.created_at)))

const datasets = computed(() => [
    {
        label: 'Score Wilks',
        data: sortedData.value.map((item) => parseFloat(item.score)),
        fill: true,
        tension: 0.4,
        borderColor: jeton('accent-primary'),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
            gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
            return gradient
        },
        borderWidth: 3,
        pointRadius: 4,
        pointBackgroundColor: jeton('accent-primary'),
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: jeton('accent-primary'),
        pointHoverBorderWidth: 3,
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => `Score : ${nombre(context.parsed.y, 2, 2)}` },
}
</script>

<template>
    <BaseChart
        description="Historique du score Wilks"
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        :infobulle="infobulle"
        :axe-y="{ grid: { borderDash: [4, 4] } }"
    />
</template>
