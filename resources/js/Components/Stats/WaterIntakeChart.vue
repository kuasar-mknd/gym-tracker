<script setup>
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
    goal: {
        type: Number,
        default: 2500,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.day_name.substring(0, 3)),
        datasets: [
            {
                label: 'Water (ml)',
                data: props.data.map((item) => item.total),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, '#06B6D4') // Cyan
                    gradient.addColorStop(1, '#3B82F6') // Blue
                    return gradient
                },
                borderRadius: 6,
                hoverBackgroundColor: '#22D3EE',
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
                color: 'rgba(255, 255, 255, 0.05)',
            },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold' },
                precision: 0,
            },
            suggestedMax: props.goal,
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold' },
            },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: 'rgba(30, 41, 59, 0.9)',
            titleColor: '#e2e8f0',
            bodyColor: '#e2e8f0',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.raw} ml`,
            }
        },
    },
}))
</script>

<template>
    <div class="h-64 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
