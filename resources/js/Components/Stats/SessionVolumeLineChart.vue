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
    const labels = props.data.map((d) => d.date)
    const volumes = props.data.map((d) => d.volume)

    return {
        labels,
        datasets: [
            {
                label: 'Volume par séance',
                data: volumes,
                fill: true,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(136, 0, 255, 0.4)') // vivid-violet
                    gradient.addColorStop(1, 'rgba(255, 0, 128, 0)') // hot-pink

                    return gradient
                },
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#8800FF') // vivid-violet
                    gradient.addColorStop(1, '#FF0080') // hot-pink

                    return gradient
                },
                borderWidth: 4,
                tension: 0.4,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#8800FF',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
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
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(136, 0, 255, 0.2)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} kg`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                padding: 10,
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
    interaction: {
        mode: 'index',
        intersect: false,
    },
    layout: {
        padding: {
            left: -10,
            right: -10,
            bottom: 0,
            top: 10,
        },
    },
}
</script>

<template>
    <div class="h-full w-full" style="filter: drop-shadow(0 4px 6px rgba(136, 0, 255, 0.15))">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
