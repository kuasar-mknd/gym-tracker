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
    // Reverse the data so it reads chronologically from left to right
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((d) => {
        const date = new Date(d.started_at)
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'short',
        })
    })

    const durations = reversedData.map((d) => {
        if (d.duration_minutes) {
            return d.duration_minutes
        }
        if (d.ended_at) {
            return Math.round((new Date(d.ended_at) - new Date(d.started_at)) / 60000)
        }
        return 0
    })

    return {
        labels,
        datasets: [
            {
                label: 'Durée (min)',
                data: durations,
                fill: true,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(0, 209, 255, 0.3)') // cyan-pure with opacity
                    gradient.addColorStop(1, 'rgba(0, 209, 255, 0)')

                    return gradient
                },
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#00D1FF') // cyan-pure
                    gradient.addColorStop(1, '#00FF66') // neon-green

                    return gradient
                },
                borderWidth: 4,
                tension: 0.4,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#00D1FF',
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
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(0, 209, 255, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} min`,
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
            beginAtZero: true,
        },
    },
    interaction: {
        mode: 'index',
        intersect: false,
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
