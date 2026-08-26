<script setup>
import { Line } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

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
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: jeton('accent-tertiary'),
                pointHoverBorderWidth: 3,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.9),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            padding: 12,
            cornerRadius: 12,
            displayColors: false,
            borderWidth: 1,
            borderColor: jetonTransparent('accent-secondary', 0.1),
            callbacks: {
                label: (context) => `${context.parsed.y} %`,
            },
        },
    },
    scales: {
        x: {
            display: false,
        },
        y: {
            display: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                display: false,
            },
        },
    },
}))
</script>

<template>
    <div class="h-40 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
/* Liquid Glass aesthetic drop shadow for the chart line */
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-accent-secondary) r g b / 0.2));
}
</style>
