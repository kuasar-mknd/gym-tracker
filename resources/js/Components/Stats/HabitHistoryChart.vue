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
                    gradient.addColorStop(0, '#8B5CF6') // Violet
                    gradient.addColorStop(1, '#EC4899') // Pink
                    return gradient
                },
                borderRadius: 4,
                barThickness: 'flex',
                maxBarThickness: 20,
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
            borderColor: 'rgba(139, 92, 246, 0.1)',
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
                color: '#94a3b8', // text-slate-400
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
                color: '#94a3b8',
                font: { size: 10, weight: 'bold' },
                stepSize: 1,
            },
            grid: {
                color: 'rgba(255, 255, 255, 0.05)',
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
