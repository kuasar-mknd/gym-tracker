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
    // Filter out parts with no difference
    const diffData = props.data.filter((item) => item.diff !== 0)

    return {
        labels: diffData.map((item) => item.part),
        datasets: [
            {
                label: 'Différence',
                data: diffData.map((item) => item.diff),
                backgroundColor: (context) => {
                    const value = context.raw
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    if (value > 0) {
                        // Green/Cyan for growth
                        gradient.addColorStop(0, jeton('trend-up'))
                        gradient.addColorStop(1, jetonTransparent('trend-up', 0.55))
                    } else {
                        // Red/Orange for decrease
                        gradient.addColorStop(0, jeton('trend-down'))
                        gradient.addColorStop(1, jetonTransparent('trend-down', 0.55))
                    }
                    return gradient
                },
                borderRadius: 6,
                borderSkipped: false,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y', // Make it a horizontal bar chart
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('shadow-cast', 0.1),
            borderWidth: 1,
            cornerRadius: 12,
            padding: 12,
            callbacks: {
                label: (context) => {
                    const value = context.parsed.x
                    const sign = value > 0 ? '+' : ''
                    // Find the unit for this part
                    const partData = props.data.find((d) => d.part === context.label)
                    const unit = partData ? partData.unit : ''
                    return `${sign}${value} ${unit}`
                },
            },
        },
    },
    scales: {
        x: {
            display: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: jetonTransparent('shadow-cast', 0.05),
            },
            border: { display: false },
        },
        y: {
            display: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 11, weight: 'bold' },
            },
            grid: {
                display: false,
            },
            border: { display: false },
        },
    },
}))
</script>

<template>
    <div class="h-64 w-full">
        <Bar v-if="chartData.labels.length > 0" :data="chartData" :options="chartOptions" />
        <div v-else class="text-text-muted flex h-full items-center justify-center font-medium">
            Aucun changement enregistré
        </div>
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-shadow-cast) r g b / 0.05));
}
</style>
