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

const labels = computed(() => props.data.map((item) => item.label))

const datasets = computed(() => [
    {
        label: 'Séries',
        data: props.data.map((item) => item.count),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-info')) // Blue-500
            gradient.addColorStop(1, jeton('accent-tertiary')) // Violet-500
            return gradient
        },
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-tertiary'), // Indigo-500
    },
])

const infobulle = {
    accent: 'accent-info',
    callbacks: {
        label: (context) => `${context.raw} séries`,
        title: (context) => `${context[0].label} kg`,
    },
}

// Chart.js passe l'index de la graduation, pas son libellé : la tranche se lit
// depuis l'échelle, d'où la fonction classique et son `this`.
const axeX = {
    ticks: {
        callback: function (val) {
            return this.getLabelForValue(val)
        },
    },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="infobulle"
        :axe-x="axeX"
        :axe-y="{ ticks: { stepSize: 1 } }"
    />
</template>
