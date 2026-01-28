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
                    // Cyan to Blue/Violet gradient
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, '#22d3ee') // cyan-400
                    gradient.addColorStop(1, '#8b5cf6') // violet-500
                    return gradient
                },
                borderRadius: 8,
                hoverBackgroundColor: '#06b6d4', // cyan-500
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
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
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
            borderWidth: 1,
            borderColor: 'rgba(0, 0, 0, 0.05)',
            callbacks: {
                label: (context) => `${context.parsed.y.toLocaleString()} kg`,
            },
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
