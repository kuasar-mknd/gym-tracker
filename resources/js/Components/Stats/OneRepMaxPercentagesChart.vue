<script setup>
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.percent + '%'),
        datasets: [
            {
                label: 'Poids (kg)',
                data: props.data.map((item) => item.value),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#FF4500') // Electric Orange
                    gradient.addColorStop(1, '#FF8C00') // Lighter Orange
                    return gradient
                },
                borderRadius: 4,
                barThickness: 16,
                maxBarThickness: 24,
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
            bodyColor: '#475569',
            borderColor: 'rgba(0, 0, 0, 0.1)',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => {
                    const item = props.data[context.dataIndex]
                    return `${parseFloat(item.value.toFixed(1))} kg (≈ ${item.reps} reps)`
                },
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(100, 116, 139, 0.1)',
                drawBorder: false,
            },
            ticks: {
                color: '#64748B',
                font: {
                    family: "'Plus Jakarta Sans', sans-serif",
                    size: 11,
                    weight: 'bold',
                },
                callback: (value) => value + ' kg',
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
                    family: "'Plus Jakarta Sans', sans-serif",
                    size: 11,
                    weight: 'bold',
                },
            },
            border: { display: false },
        },
    },
    animation: {
        duration: 2000,
        easing: 'easeOutQuart',
    },
}
</script>

<template>
    <div class="h-full w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(255, 69, 0, 0.2));
}
</style>
