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

const chartData = computed(() => {
    // Clone and reverse to have oldest first
    const sortedData = [...props.data].reverse()

    return {
        labels: sortedData.map((item) => {
             return new Date(item.created_at).toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric'
            })
        }),
        datasets: [
            {
                label: 'Cible (kcal)',
                data: sortedData.map((item) => item.target_calories),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#FF5500') // electric-orange
                    gradient.addColorStop(1, '#FF0080') // hot-pink
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(255, 85, 0, 0.2)')
                    gradient.addColorStop(1, 'rgba(255, 85, 0, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: '#FF5500',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#FF5500',
                pointHoverBorderWidth: 3,
            },
            {
                label: 'TDEE (kcal)',
                data: sortedData.map((item) => item.tdee),
                fill: false,
                tension: 0.4,
                borderColor: '#94a3b8', // Slate-400
                borderDash: [5, 5],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
            }
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'top',
            align: 'end',
            labels: {
                usePointStyle: true,
                boxWidth: 8,
                font: { size: 10, family: 'Space Grotesk' },
                color: '#64748B' // text-muted
            }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            displayColors: true,
            boxPadding: 4,
            borderWidth: 1,
            borderColor: 'rgba(255, 85, 0, 0.1)',
            titleFont: { family: 'Space Grotesk', weight: 'bold' },
            bodyFont: { family: 'Space Grotesk' },
            callbacks: {
                label: (context) => `${context.dataset.label}: ${context.parsed.y}`,
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
                color: '#94a3b8',
                font: { size: 10 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 6
            }
        },
        y: {
            display: true,
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: 'rgba(148, 163, 184, 0.1)',
                borderDash: [4, 4],
            },
        },
    },
    interaction: {
        mode: 'index',
        intersect: false,
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
    filter: drop-shadow(0 4px 6px rgba(255, 85, 0, 0.1));
}
</style>
