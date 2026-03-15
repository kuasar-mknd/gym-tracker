<script setup>
import { Scatter } from 'vue-chartjs'
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
                backgroundColor: 'rgba(255, 107, 0, 0.6)', // Electric Orange with opacity
                borderColor: '#FF6B00',
                borderWidth: 1,
                pointRadius: 5,
                pointHoverRadius: 8,
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
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#0F172A',
            bodyColor: '#0F172A',
            borderColor: 'rgba(0, 0, 0, 0.1)',
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
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
            },
        },
        y: {
            title: {
                display: true,
                text: 'Répétitions',
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
            },
            beginAtZero: true,
        },
    },
}
</script>

<template>
    <div class="h-full w-full">
        <Scatter :data="chartData" :options="chartOptions" />
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(255, 107, 0, 0.2));
}
</style>
