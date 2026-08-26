<script setup>
import { Line } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
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
                label: 'Durée (min)',
                data: props.data.map((item) => item.duration),
                fill: true,
                tension: 0.4,
                borderColor: jeton('accent-tertiary'),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jetonTransparent('accent-tertiary', 0.2))
                    gradient.addColorStop(1, jetonTransparent('accent-tertiary', 0))
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: '#fff',
                pointBorderColor: jeton('accent-tertiary'),
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: jeton('accent-tertiary'),
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.9),
            padding: 12,
            cornerRadius: 12,
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            displayColors: false,
            borderColor: jetonTransparent('accent-tertiary', 0.1),
            borderWidth: 1,
            callbacks: {
                label: (context) => `${context.parsed.y} min`,
            },
        },
    },
    scales: {
        y: {
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
    interaction: {
        mode: 'index',
        intersect: false,
    },
}))
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
