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

// Sort data by date ascending
const sortedData = computed(() => {
    return [...props.data].sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
})

const chartData = computed(() => {
    return {
        labels: sortedData.value.map((item) => new Date(item.created_at).toLocaleDateString()),
        datasets: [
            {
                label: 'Score Wilks',
                data: sortedData.value.map((item) => parseFloat(item.score)),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, jeton('accent-primary')) // Orange
                    gradient.addColorStop(1, jeton('accent-secondary')) // Pink
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
                    gradient.addColorStop(1, jetonTransparent('accent-secondary', 0))
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: jeton('accent-primary'),
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: jeton('accent-secondary'),
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
            borderColor: jetonTransparent('accent-primary', 0.1),
            callbacks: {
                label: (context) => `Score: ${parseFloat(context.parsed.y).toFixed(2)}`,
            },
        },
    },
    scales: {
        x: {
            display: false,
            grid: {
                display: false,
            },
        },
        y: {
            display: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: jetonTransparent('text-muted', 0.1),
                borderDash: [4, 4],
            },
        },
    },
}))
</script>

<template>
    <div class="h-64 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-accent-primary) r g b / 0.2));
}
</style>
