<script setup>
import { Bar } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
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
        labels: props.data.map((d) => d.date),
        datasets: [
            {
                label: 'Séries (Sets)',
                data: props.data.map((d) => d.sets),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, jeton('accent-primary')) // Electric orange shade
                    gradient.addColorStop(1, jeton('accent-secondary')) // Hot pink shade
                    return gradient
                },
                borderRadius: 4,
                barPercentage: 0.5,
                borderWidth: 0,
                hoverBackgroundColor: jeton('accent-tertiary'),
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('accent-secondary', 0.2),
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} séries`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
}))
</script>

<template>
    <div class="h-full w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
