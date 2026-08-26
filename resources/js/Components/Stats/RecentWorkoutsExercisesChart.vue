<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Reverse the data so it reads chronologically from left to right
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((d) => {
        const date = new Date(d.started_at)
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'short',
        })
    })

    const exerciseCounts = reversedData.map((d) => d.workout_lines_count || 0)

    return {
        labels,
        datasets: [
            {
                label: 'Exercices',
                data: exerciseCounts,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, jeton('accent-info')) // cyan-pure
                    gradient.addColorStop(1, jeton('accent-state')) // neon-green

                    return gradient
                },
                borderRadius: 8,
                barPercentage: 0.6,
                borderWidth: 0,
                hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('accent-state', 0.2),
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} exercices`,
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
            display: false,
            beginAtZero: true,
            ticks: {
                stepSize: 1,
            },
        },
    },
}))
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
