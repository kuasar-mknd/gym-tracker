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
                borderColor: '#8800FF',
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(136, 0, 255, 0.2)')
                    gradient.addColorStop(1, 'rgba(136, 0, 255, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 4,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#8800FF',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#8800FF',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
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
            padding: 12,
            cornerRadius: 12,
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            displayColors: false,
            borderColor: 'rgba(136, 0, 255, 0.1)',
            borderWidth: 1,
            callbacks: {
                label: (context) => `${context.parsed.y} min`,
            },
        },
    },
    scales: {
        y: {
            grid: {
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
    },
    interaction: {
        mode: 'index',
        intersect: false,
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
