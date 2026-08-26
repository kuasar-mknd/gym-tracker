<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { computed } from 'vue'
import { formatVolumeTick } from '@/Utils/volumeAxis'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.month),
        datasets: [
            {
                label: 'Volume (kg)',
                data: props.data.map((item) => item.volume),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
                    gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink
                    return gradient
                },
                borderRadius: 4,
                hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
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
                color: jetonTransparent('shadow-cast', 0.05),
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                callback: formatVolumeTick,
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
            displayColors: false,
            callbacks: {
                label: (context) => `${Number(context.raw).toLocaleString()} kg`,
            },
        },
    },
}))
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
