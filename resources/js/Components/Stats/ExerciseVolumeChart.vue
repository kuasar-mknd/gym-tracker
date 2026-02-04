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
                    gradient.addColorStop(0, '#FF5500') // electric-orange
                    gradient.addColorStop(1, '#FF0080') // hot-pink
                    return gradient
                },
                borderRadius: 4,
                hoverBackgroundColor: '#8800FF', // vivid-violet
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
                callback: (value) => {
                    if (value >= 1000) return (value / 1000).toFixed(1) + 'k'
                    return value
                },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${Number(context.raw).toLocaleString()} kg`,
            },
        },
    },
}
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
