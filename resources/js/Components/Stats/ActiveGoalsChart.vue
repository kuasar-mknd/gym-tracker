<script setup>
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js'
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
        labels: props.data.map((g) => (g.title && g.title.length > 15 ? g.title.substring(0, 15) + '...' : g.title)),
        datasets: [
            {
                label: 'Progression (%)',
                data: props.data.map((g) => Math.round(g.progress_pct || 0)),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#f97316') // electric orange
                    gradient.addColorStop(1, '#fb923c')
                    return gradient
                },
                borderRadius: 4,
                barPercentage: 0.5,
                borderWidth: 0,
                hoverBackgroundColor: '#ea580c',
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y', // Horizontal bar chart
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(249, 115, 22, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                title: (context) => {
                    const index = context[0].dataIndex
                    return props.data[index].title
                },
                label: (context) => `${context.parsed.x}% complété`,
            },
        },
    },
    scales: {
        x: {
            min: 0,
            max: 100,
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
                drawBorder: false,
            },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                callback: (value) => value + '%',
            },
            border: { display: false },
        },
        y: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
            },
            border: { display: false },
        },
    },
}
</script>

<template>
    <div class="h-full w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
