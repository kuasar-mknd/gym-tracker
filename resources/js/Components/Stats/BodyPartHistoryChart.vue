<script setup>
import { Line } from 'vue-chartjs'
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
    label: {
        type: String,
        default: 'Measurement',
    },
    unit: {
        type: String,
        default: 'cm',
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) =>
            new Date(item.measured_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
        ),
        datasets: [
            {
                label: props.label,
                data: props.data.map((item) => item.value),
                fill: true,
                tension: 0.4,
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#C084FC') // Purple
                    gradient.addColorStop(1, '#E879F9') // Pink
                    return gradient
                },
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(192, 132, 252, 0.2)')
                    gradient.addColorStop(1, 'rgba(192, 132, 252, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: '#C084FC',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#C084FC',
                pointHoverBorderWidth: 3,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
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
            borderColor: 'rgba(192, 132, 252, 0.1)',
            callbacks: {
                label: (context) => `${context.parsed.y} ${props.unit}`,
            },
        },
    },
    scales: {
        x: {
            display: false,
        },
        y: {
            display: true,
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                display: false,
            },
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
