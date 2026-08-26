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
    // Clone and reverse to have oldest first
    const sortedData = [...props.data].reverse()

    return {
        labels: sortedData.map((item) => {
            return new Date(item.created_at).toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            })
        }),
        datasets: [
            {
                label: 'Cible (kcal)',
                data: sortedData.map((item) => item.target_calories),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
                    gradient.addColorStop(1, jeton('accent-secondary')) // hot-pink
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, jetonTransparent('accent-primary', 0.2))
                    gradient.addColorStop(1, jetonTransparent('accent-primary', 0))
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: jeton('accent-primary'),
                pointBorderColor: jeton('surface-card'),
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: jeton('surface-card'),
                pointHoverBorderColor: jeton('accent-primary'),
                pointHoverBorderWidth: 3,
            },
            {
                label: 'TDEE (kcal)',
                data: sortedData.map((item) => item.tdee),
                fill: false,
                tension: 0.4,
                borderColor: jeton('text-muted'), // Slate-400
                borderDash: [5, 5],
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'top',
            align: 'end',
            labels: {
                usePointStyle: true,
                boxWidth: 8,
                font: { size: 10, family: 'Space Grotesk' },
                color: jeton('text-muted'), // text-muted
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            padding: 12,
            cornerRadius: 12,
            displayColors: true,
            boxPadding: 4,
            borderWidth: 1,
            borderColor: jetonTransparent('accent-primary', 0.1),
            titleFont: { family: 'Space Grotesk', weight: 'bold' },
            bodyFont: { family: 'Space Grotesk' },
            callbacks: {
                label: (context) => `${context.dataset.label}: ${context.parsed.y}`,
            },
        },
    },
    scales: {
        x: {
            display: true,
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 6,
            },
        },
        y: {
            display: true,
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: jetonTransparent('text-muted', 0.1),
                borderDash: [4, 4],
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
    <div class="h-64 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-accent-primary) r g b / 0.1));
}
</style>
