<script setup>
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
    goal: {
        type: Number,
        default: 2500,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.day_name.substring(0, 3)),
        datasets: [
            {
                label: 'Volume (ml)',
                data: props.data.map((item) => item.total),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, '#3B82F6') // Blue-500
                    gradient.addColorStop(1, '#93C5FD') // Blue-300
                    return gradient
                },
                borderRadius: 6,
                hoverBackgroundColor: '#2563EB', // Blue-600
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            max: Math.max(props.goal, ...props.data.map((item) => item.total)) + 200, // Add some padding above goal
            grid: {
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
                stepSize: 500,
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
                textTransform: 'uppercase',
            },
        },
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
            borderWidth: 1,
            borderColor: 'rgba(0, 0, 0, 0.05)',
            callbacks: {
                label: (context) => `${context.raw} ml`,
                title: (context) => context[0].label,
            },
        },
    },
}
</script>

<template>
    <div class="h-[300px] w-full pt-4">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
