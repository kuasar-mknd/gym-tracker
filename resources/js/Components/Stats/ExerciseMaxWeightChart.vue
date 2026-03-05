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
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Poids Max (kg)',
                data: props.data.map((item) => item.weight),
                borderColor: '#F97316', // Orange-500
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(249, 115, 22, 0.5)')
                    gradient.addColorStop(1, 'rgba(249, 115, 22, 0.0)')
                    return gradient
                },
                borderWidth: 3,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#F97316',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: false,
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
                drawBorder: false,
            },
            ticks: {
                color: '#64748B',
                font: {
                    size: 10,
                    weight: 'bold',
                },
            },
            border: { display: false },
        },
        x: {
            grid: {
                display: false,
                drawBorder: false,
            },
            ticks: {
                color: '#64748B',
                font: {
                    size: 10,
                    weight: 'bold',
                },
                maxRotation: 45,
                minRotation: 45,
            },
            border: { display: false },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            borderColor: 'rgba(0,0,0,0.1)',
            borderWidth: 1,
            callbacks: {
                label: (context) => `${context.raw} kg`,
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
