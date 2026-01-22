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
    const labels = props.data.map((d) => d.day_label)
    const counts = props.data.map((d) => d.count)

    return {
        labels,
        datasets: [
            {
                label: 'Habitudes complétées',
                data: counts,
                fill: true,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(52, 211, 153, 0.2)') // Emerald
                    gradient.addColorStop(1, 'rgba(52, 211, 153, 0)')

                    return gradient
                },
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#34d399') // Emerald 400
                    gradient.addColorStop(1, '#06b6d4') // Cyan 500

                    return gradient
                },
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#06b6d4',
                pointBorderWidth: 2,
                pointRadius: 0,
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
            borderColor: 'rgba(52, 211, 153, 0.2)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} complétées`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                maxTicksLimit: 7,
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
            suggestedMax: 3,
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
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
