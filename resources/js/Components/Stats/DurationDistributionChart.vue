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
        labels: props.data.map((item) => item.range),
        datasets: [
            {
                label: 'Séances',
                data: props.data.map((item) => item.count),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#00E5FF') // Cyan
                    gradient.addColorStop(1, '#00FF88') // Green

                    return gradient
                },
                borderRadius: 6,
                borderSkipped: false,
                barThickness: 24,
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
            padding: 12,
            cornerRadius: 12,
            borderColor: 'rgba(0, 229, 255, 0.1)', // Cyan tint
            borderWidth: 1,
            callbacks: {
                label: (context) => `${context.parsed.y} séance${context.parsed.y !== 1 ? 's' : ''}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#64748b',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
    layout: {
        padding: {
            top: 20,
        },
    },
}
</script>

<template>
    <div class="h-52 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
