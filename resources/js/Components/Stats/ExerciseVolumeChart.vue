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
})

const chartData = computed(() => {
    // Clone and reverse to show oldest to newest (left to right)
    const reversedData = [...props.data].reverse()

    return {
        labels: reversedData.map((item) => item.formatted_date),
        datasets: [
            {
                label: 'Volume (kg)',
                data: reversedData.map((item) => item.volume),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(124, 58, 237, 0.8)') // Violet
                    gradient.addColorStop(1, 'rgba(249, 115, 22, 0.8)') // Orange
                    return gradient
                },
                borderRadius: 4,
                hoverBackgroundColor: '#7C3AED',
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
            grid: {
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
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
            borderColor: 'rgba(124, 58, 237, 0.1)',
            callbacks: {
                label: function (context) {
                    let label = context.dataset.label || ''
                    if (label) {
                        label += ': '
                    }
                    if (context.parsed.y !== null) {
                        label += context.parsed.y.toLocaleString() + ' kg'
                    }
                    return label
                },
            },
        },
    },
}
</script>

<template>
    <div class="h-full w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
