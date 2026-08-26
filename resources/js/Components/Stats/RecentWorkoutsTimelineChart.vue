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
import { workoutDurationMinutes } from '@/Utils/workoutDuration'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // We reverse the data to show chronological order from left to right
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((workout) =>
        new Date(workout.started_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }),
    )

    const durations = reversedData.map(workoutDurationMinutes)

    return {
        labels,
        datasets: [
            {
                label: 'Durée (min)',
                data: durations,
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, jeton('accent-tertiary')) // vivid-violet
                    gradient.addColorStop(1, jeton('accent-primary')) // electric-orange
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.2))
                    gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: jeton('accent-primary'),
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: jeton('accent-primary'),
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
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('accent-primary', 0.2),
            borderWidth: 1,
            padding: 12,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} minutes`,
                title: (context) => {
                    // Reversed again because the chart data is reversed
                    const workout = [...props.data].reverse()[context[0].dataIndex]
                    return workout.name || 'Séance'
                },
            },
        },
    },
    scales: {
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
        },
        y: {
            display: false,
            beginAtZero: true,
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
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-accent-tertiary) r g b / 0.2));
}
</style>
