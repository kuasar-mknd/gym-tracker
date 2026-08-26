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
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.month),
        datasets: [
            {
                label: 'Séances',
                backgroundColor: jetonTransparent('accent-info', 0.6),
                borderRadius: 4,
                data: props.data.map((item) => item.count),
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: jetonTransparent('shadow-cast', 0.05),
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                stepSize: 1,
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
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
        },
    },
}))
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
