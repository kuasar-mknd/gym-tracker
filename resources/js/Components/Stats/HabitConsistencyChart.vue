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
    return {
        labels: props.data.map((item) =>
            new Date(item.date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
        ),
        datasets: [
            {
                label: 'Habits Completed',
                data: props.data.map((item) => item.count),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#34D399') // Emerald 400
                    gradient.addColorStop(1, '#2DD4BF') // Teal 400
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(52, 211, 153, 0.2)')
                    gradient.addColorStop(1, 'rgba(52, 211, 153, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 0, // Hide points for cleaner look, show on hover
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#34D399',
                pointHoverBorderWidth: 3,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index',
        intersect: false,
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
            borderWidth: 1,
            borderColor: 'rgba(52, 211, 153, 0.2)',
            callbacks: {
                label: (context) => `${context.parsed.y} Complétés`,
            },
        },
    },
    scales: {
        x: {
            display: false,
        },
        y: {
            display: true,
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold' },
                stepSize: 1,
                precision: 0,
            },
            grid: {
                display: false,
            },
            beginAtZero: true,
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
