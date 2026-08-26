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
                    gradient.addColorStop(0, jeton('accent-info')) // Blue-500
                    gradient.addColorStop(1, jetonTransparent('accent-info', 0.55)) // Blue-300
                    return gradient
                },
                borderRadius: 6,
                hoverBackgroundColor: jeton('accent-info'), // Blue-600
            },
        ],
    }
})

/**
 * computed, not a plain object. Evaluated once at setup, the axis maximum was
 * frozen on the props of the first render — and an Inertia visit reuses the
 * component instance, so setup never runs again. Add enough water to pass that
 * first maximum and today's bar was clipped at the top of the chart while the
 * ring above it announced the real total.
 */
const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            max: Math.max(props.goal, ...props.data.map((item) => item.total)) + 200, // Add some padding above goal
            grid: {
                color: jetonTransparent('shadow-cast', 0.03),
            },
            ticks: {
                stepSize: 500,
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
                textTransform: 'uppercase',
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
                label: (context) => `${context.raw} ml`,
                title: (context) => context[0].label,
            },
        },
    },
}))
</script>

<template>
    <div class="h-[300px] w-full pt-4">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
