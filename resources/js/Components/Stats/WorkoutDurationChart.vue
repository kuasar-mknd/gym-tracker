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
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderColor: '#3b82f6',
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#3b82f6',
                pointRadius: 4,
                pointHoverRadius: 6,
                data: props.data.map((item) => item.duration),
                tension: 0.3,
                fill: true,
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
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 12,
            cornerRadius: 8,
            titleColor: '#fff',
            bodyColor: '#fff',
            callbacks: {
                label: function (context) {
                    return context.parsed.y + ' min'
                },
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(255, 255, 255, 0.05)',
            },
            ticks: {
                color: 'rgba(255, 255, 255, 0.5)',
                font: { size: 10 },
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: 'rgba(255, 255, 255, 0.5)',
                font: { size: 10 },
            },
        },
    },
}
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
