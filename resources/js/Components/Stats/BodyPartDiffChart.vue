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
                        gradient.addColorStop(0, '#34d399')
                        gradient.addColorStop(1, '#059669')
                    } else {
                        // Red/Orange for decrease
                        gradient.addColorStop(0, '#f87171')
                        gradient.addColorStop(1, '#dc2626')
                    }
                    return gradient
                },
                borderRadius: 6,
                borderSkipped: false,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y', // Make it a horizontal bar chart
    plugins: {
        legend: {
            display: false,
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(0, 0, 0, 0.1)',
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
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
            },
            border: { display: false },
        },
        y: {
            display: true,
            ticks: {
                color: '#64748B',
                font: { size: 11, weight: 'bold' },
            },
            grid: {
                display: false,
            },
            border: { display: false },
        },
    },
}
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
    filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.05));
}
</style>
