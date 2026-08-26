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
    return {
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Habitudes complétées',
                data: props.data.map((item) => item.count),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, jeton('accent-tertiary')) // Violet
                    gradient.addColorStop(1, jeton('accent-secondary')) // Pink
                    return gradient
                },
                borderRadius: 4,
                barThickness: 'flex',
                maxBarThickness: 20,
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
            borderColor: jetonTransparent('accent-tertiary', 0.1),
            callbacks: {
                label: (context) => `${context.parsed.y} habitudes`,
            },
        },
    },
    scales: {
        x: {
            display: true,
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'), // text-text-muted
                font: { size: 10 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 7,
            },
        },
        y: {
            display: true,
            beginAtZero: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                stepSize: 1,
            },
            grid: {
                color: jetonTransparent('surface-card', 0.05),
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
