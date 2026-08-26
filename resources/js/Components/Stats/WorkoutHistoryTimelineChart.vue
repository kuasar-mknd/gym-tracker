<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    LineController,
} from 'chart.js'
import { computed } from 'vue'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    LineController,
    Title,
    Tooltip,
    Legend,
)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Reverse the data to show oldest to newest (left to right)
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((d) => {
        const date = new Date(d.started_at)
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'short',
        })
    })

    const volumes = reversedData.map((d) => d.workout_volume || 0)
    const durations = reversedData.map(workoutDurationMinutes)

    return {
        labels,
        datasets: [
            {
                type: 'line',
                label: 'Durée (min)',
                data: durations,
                borderColor: jeton('accent-secondary'), // hot-pink
                borderWidth: 3,
                pointBackgroundColor: jeton('surface-card'),
                pointBorderColor: jeton('accent-secondary'),
                pointBorderWidth: 2,
                pointRadius: 4,
                tension: 0.4,
                yAxisID: 'y1',
            },
            {
                type: 'bar',
                label: 'Volume (kg)',
                data: volumes,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, jetonTransparent('accent-primary', 0.4)) // electric-orange with opacity
                    gradient.addColorStop(1, jetonTransparent('accent-secondary', 0.8)) // hot-pink with opacity

                    return gradient
                },
                borderRadius: 8,
                barPercentage: 0.6,
                yAxisID: 'y',
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'top',
            labels: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('accent-primary', 0.2),
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            callbacks: {
                label: (context) => {
                    const label = context.dataset.label || ''
                    if (context.datasetIndex === 0) {
                        return `${label}: ${context.parsed.y} min`
                    }
                    return `${label}: ${context.parsed.y} kg`
                },
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
            },
            border: { display: false },
        },
        y: {
            type: 'linear',
            display: false,
            position: 'left',
            beginAtZero: true,
            grid: {
                color: jetonTransparent('surface-card', 0.05),
            },
        },
        y1: {
            type: 'linear',
            display: false,
            position: 'right',
            beginAtZero: true,
            grid: {
                drawOnChartArea: false,
            },
        },
    },
}))
</script>

<template>
    <div class="h-64 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
