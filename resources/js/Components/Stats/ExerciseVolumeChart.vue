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
    return {
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Volume (kg)',
                data: props.data.map((item) => item.volume),
                backgroundColor: '#FF9F1C',
                borderRadius: 4,
                barPercentage: 0.6,
                categoryPercentage: 0.8,
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
            borderWidth: 1,
            borderColor: 'rgba(255, 159, 28, 0.2)', // Electric Orange with low opacity
            callbacks: {
                label: (context) => `Volume: ${context.raw} kg`,
            },
        },
    },
    scales: {
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
        y: {
            grid: {
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
