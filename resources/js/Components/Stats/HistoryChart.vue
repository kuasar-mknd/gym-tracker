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
    // Expecting history array to have formatted_date and best_1rm
    // The history array is typically sorted descending (newest first).
    // Let's reverse it so time flows left to right.
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((d) => d.formatted_date)
    const values = reversedData.map((d) => Math.round(d.best_1rm))

    return {
        labels,
        datasets: [
            {
                label: 'Meilleur 1RM (kg)',
                data: values,
                borderColor: '#FF0080', // hot-pink
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, 'rgba(255, 0, 128, 0.05)')
                    gradient.addColorStop(1, 'rgba(255, 0, 128, 0.4)')

                    return gradient
                },
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#FF0080',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4, // Smooth curve
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
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(255, 0, 128, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
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
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: false,
            // Add some padding to top and bottom to make the chart look better
            suggestedMin: (context) => {
                const values = context.chart.data.datasets[0].data
                return Math.min(...values) * 0.9
            },
            suggestedMax: (context) => {
                const values = context.chart.data.datasets[0].data
                return Math.max(...values) * 1.1
            },
        },
    },
    interaction: {
        intersect: false,
        mode: 'index',
    },
}
</script>

<template>
    <div class="h-full w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
