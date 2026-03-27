<script setup>
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
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
        labels: props.data.map((pr) => pr.exercise?.name || 'Inconnu'),
        datasets: [
            {
                label: 'Valeur',
                data: props.data.map((pr) => parseFloat(pr.value)),
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    // Liquid Glass inspired horizontal gradient
                    const gradient = ctx.createLinearGradient(0, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#FACC15') // Yellow 400
                    gradient.addColorStop(1, '#F97316') // Orange 500
                    return gradient
                },
                borderRadius: 8,
                barThickness: 16,
                borderSkipped: false,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: 'y', // Horizontal bar chart
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
            displayColors: false,
            borderWidth: 1,
            borderColor: 'rgba(249, 115, 22, 0.2)', // Orange border
            callbacks: {
                label: (context) => {
                    const pr = props.data[context.dataIndex]
                    const unit = pr.type === 'max_volume_set' ? '' : ' kg'
                    return `${context.parsed.x}${unit}`
                },
            },
        },
    },
    scales: {
        x: {
            display: false, // Hide x-axis grid and labels for cleaner look
            grid: {
                display: false,
            },
            beginAtZero: true,
        },
        y: {
            display: true,
            ticks: {
                color: '#64748B', // Slate 500
                font: { size: 11, weight: 'bold', family: "'Inter', sans-serif" },
                autoSkip: false,
            },
            grid: {
                display: false,
            },
            border: {
                display: false,
            },
        },
    },
}
</script>

<template>
    <div class="h-32 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    /* Liquid Glass aesthetic drop shadow */
    filter: drop-shadow(0 4px 6px rgba(249, 115, 22, 0.25));
}
</style>
