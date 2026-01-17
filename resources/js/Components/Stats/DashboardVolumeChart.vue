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
import { computed, onMounted, ref } from 'vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartRef = ref(null)

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.day_name),
        datasets: [
            {
                label: 'Volume (kg)',
                data: props.data.map((item) => item.volume),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#FF5500')
                    gradient.addColorStop(0.5, '#FF0080')
                    gradient.addColorStop(1, '#8800FF')
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
                borderWidth: 4,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#FF0080',
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
            borderColor: 'rgba(25, 118, 210, 0.1)',
            callbacks: {
                label: (context) => `${context.parsed.y} kg`,
            },
        },
    },
    scales: {
        x: {
            display: false,
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
}
</script>

<template>
    <div class="h-40 w-full">
        <Line :data="chartData" :options="chartOptions" ref="chartRef" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(255, 85, 0, 0.2));
}
</style>
