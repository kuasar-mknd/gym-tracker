<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
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
    return {
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Durée (min)',
                data: props.data.map((item) => item.duration),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jeton('accent-info')) // Cyan-500
                    gradient.addColorStop(1, jetonTransparent('accent-info', 0.55)) // Blue-500
                    return gradient
                },
                borderRadius: 6,
                hoverBackgroundColor: jeton('accent-info'), // Sky-500
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: jetonTransparent('shadow-cast', 0.03),
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
        },
    },
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.9),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            padding: 12,
            cornerRadius: 12,
            borderWidth: 1,
            borderColor: jetonTransparent('shadow-cast', 0.05),
            callbacks: {
                label: function (context) {
                    return context.parsed.y + ' min'
                },
                afterLabel: function (context) {
                    return props.data[context.dataIndex]?.name || ''
                },
            },
        },
    },
}))
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
