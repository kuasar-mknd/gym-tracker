<script setup>
import { Line } from 'vue-chartjs'
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

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Sort data by date ascending
const sortedData = computed(() => {
    return [...props.data].sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
})

const chartData = computed(() => {
    return {
        labels: sortedData.value.map((item) => new Date(item.created_at).toLocaleDateString()),
        datasets: [
            {
                label: 'Score Wilks',
                data: sortedData.value.map((item) => parseFloat(item.score)),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#F97316') // Orange
                    gradient.addColorStop(1, '#EC4899') // Pink
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(249, 115, 22, 0.2)')
                    gradient.addColorStop(1, 'rgba(236, 72, 153, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: '#F97316',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#EC4899',
                pointHoverBorderWidth: 3,
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
            borderColor: 'rgba(249, 115, 22, 0.1)',
            callbacks: {
                label: (context) => `Score: ${parseFloat(context.parsed.y).toFixed(2)}`,
            },
        },
    },
    scales: {
        x: {
            display: false,
            grid: {
                display: false,
            },
        },
        y: {
            display: true,
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: 'rgba(148, 163, 184, 0.1)',
                borderDash: [4, 4],
            },
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(249, 115, 22, 0.2));
}
</style>
