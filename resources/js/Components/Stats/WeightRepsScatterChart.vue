<script setup>
import { Scatter } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { Chart as ChartJS, LinearScale, PointElement, LineElement, Tooltip, Legend } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(LinearScale, PointElement, LineElement, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        datasets: [
            {
                label: 'Séries',
                data: props.data,
                backgroundColor: jetonTransparent('accent-primary', 0.6), // Electric Orange with opacity
                borderColor: jeton('accent-primary'),
                borderWidth: 1,
                pointRadius: 5,
                pointHoverRadius: 8,
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
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('shadow-cast', 0.1),
            borderWidth: 1,
            cornerRadius: 12,
            padding: 12,
            callbacks: {
                label: (context) => `${context.parsed.x} kg × ${context.parsed.y} reps`,
            },
        },
    },
    scales: {
        x: {
            title: {
                display: true,
                text: 'Poids (kg)',
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: jetonTransparent('shadow-cast', 0.05),
            },
        },
        y: {
            title: {
                display: true,
                text: 'Répétitions',
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: jetonTransparent('shadow-cast', 0.05),
            },
            beginAtZero: true,
        },
    },
}))
</script>

<template>
    <div class="h-full w-full">
        <Scatter :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgb(from var(--color-accent-primary) r g b / 0.2));
}
</style>
