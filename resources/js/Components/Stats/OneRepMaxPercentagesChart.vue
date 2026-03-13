<script setup>
import { Bar } from 'vue-chartjs'
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
    // We want the chart to go from 50% up to 100%
    const sortedData = [...props.data].sort((a, b) => a.percent - b.percent)

    const labels = sortedData.map((d) => `${d.percent}%`)
    const weights = sortedData.map((d) => d.value)

    return {
        labels,
        datasets: [
            {
                label: 'Poids Estimé (kg)',
                data: weights,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#FF5500') // electric-orange
                    gradient.addColorStop(1, '#FF0080') // hot-pink

                    return gradient
                },
                borderRadius: 8,
                barPercentage: 0.6,
                borderWidth: 0,
                hoverBackgroundColor: '#8800FF', // vivid-violet
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(255, 85, 0, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => {
                    // Try to find the est reps for this data point
                    const dataPoint = [...props.data].find((d) => `${d.percent}%` === context.label)
                    const repsText = dataPoint && dataPoint.reps !== '-' ? ` (${dataPoint.reps} reps)` : ''
                    return `${context.parsed.y.toFixed(1)} kg${repsText}`
                },
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
