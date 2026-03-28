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
    // We only want completed fasts with an end_time, sorted chronologically by start_time
    const completedFasts = [...props.data]
        .filter((fast) => fast.end_time)
        .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))

    return {
        labels: completedFasts.map((fast) => {
            const date = new Date(fast.start_time)
            return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
        }),
        datasets: [
            {
                label: 'Durée (heures)',
                data: completedFasts.map((fast) => {
                    const start = new Date(fast.start_time)
                    const end = new Date(fast.end_time)
                    const diffMs = end - start
                    return (diffMs / (1000 * 60 * 60)).toFixed(1)
                }),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    // Liquid Glass gradient: Orange to Red/Pink
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#FF9F43') // Orange
                    gradient.addColorStop(1, '#FF5252') // Red
                    return gradient
                },
                borderRadius: 8,
                borderSkipped: false,
                barThickness: 16,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
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
            borderWidth: 1,
            borderColor: 'rgba(255, 159, 67, 0.2)', // Matches Orange
            callbacks: {
                label: (context) => `${context.parsed.y} h`,
            },
        },
    },
    scales: {
        x: {
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                display: false,
            },
            border: {
                display: false,
            },
        },
        y: {
            display: true,
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
                stepSize: 4,
            },
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
                drawBorder: false,
            },
            border: {
                display: false,
            },
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(255, 82, 82, 0.25));
}
</style>
