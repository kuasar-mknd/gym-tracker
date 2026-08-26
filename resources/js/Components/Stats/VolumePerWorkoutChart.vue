<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { computed } from 'vue'
import { commonTooltipOptions, volumeTooltipCallback } from './chartConfig'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Volume (kg)',
                data: props.data.map((item) => item.volume),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jeton('accent-info')) // Sky Blue
                    gradient.addColorStop(1, jeton('accent-tertiary')) // Violet
                    return gradient
                },
                borderRadius: 4,
                hoverBackgroundColor: jeton('accent-tertiary'),
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: jetonTransparent('surface-card', 0.1),
            },
            ticks: {
                color: jetonTransparent('surface-card', 0.7),
                font: { size: 10, weight: 'bold' },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: jetonTransparent('surface-card', 0.7),
                font: { size: 10, weight: 'bold' },
            },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            ...commonTooltipOptions,
            callbacks: {
                label: volumeTooltipCallback,
            },
        },
    },
}))
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
