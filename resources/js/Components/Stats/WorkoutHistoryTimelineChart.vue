<script setup>
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    LineController,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    LineController,
    Title,
    Tooltip,
    Legend,
)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Reverse the data to show oldest to newest (left to right)
    const reversedData = [...props.data].reverse()

    const labels = reversedData.map((d) => {
        const date = new Date(d.started_at)
        return date.toLocaleDateString('fr-FR', {
            day: 'numeric',
            month: 'short',
        })
    })

    const volumes = reversedData.map((d) => d.workout_volume || 0)
    const durations = reversedData.map(
        (d) =>
            d.duration_minutes ||
            (d.ended_at ? Math.round((new Date(d.ended_at) - new Date(d.started_at)) / 60000) : 0),
    )

    return {
        labels,
        datasets: [
            {
                type: 'line',
                label: 'Durée (min)',
                data: durations,
                borderColor: '#FF0080', // hot-pink
                borderWidth: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#FF0080',
                pointBorderWidth: 2,
                pointRadius: 4,
                tension: 0.4,
                yAxisID: 'y1',
            },
            {
                type: 'bar',
                label: 'Volume (kg)',
                data: volumes,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, 'rgba(255, 85, 0, 0.4)') // electric-orange with opacity
                    gradient.addColorStop(1, 'rgba(255, 0, 128, 0.8)') // hot-pink with opacity

                    return gradient
                },
                borderRadius: 8,
                barPercentage: 0.6,
                yAxisID: 'y',
            },
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
            labels: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(255, 85, 0, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            callbacks: {
                label: (context) => {
                    const label = context.dataset.label || ''
                    if (context.datasetIndex === 0) {
                        return `${label}: ${context.parsed.y} min`
                    }
                    return `${label}: ${context.parsed.y} kg`
                },
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
            type: 'linear',
            display: false,
            position: 'left',
            beginAtZero: true,
            grid: {
                color: 'rgba(255, 255, 255, 0.05)',
            },
        },
        y1: {
            type: 'linear',
            display: false,
            position: 'right',
            beginAtZero: true,
            grid: {
                drawOnChartArea: false,
            },
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
