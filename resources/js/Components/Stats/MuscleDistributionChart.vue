<script setup>
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement, CategoryScale } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, ArcElement, CategoryScale)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.category),
        datasets: [
            {
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#8AC926'],
                borderColor: 'rgba(255, 255, 255, 0.1)',
                borderWidth: 2,
                data: props.data.map((item) => item.volume),
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                color: 'rgba(255, 255, 255, 0.7)',
                padding: 12,
                boxWidth: 10,
                font: {
                    family: "'Inter', sans-serif",
                    size: 10,
                },
            },
        },
        tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            titleFont: { size: 14, weight: 'bold' },
            bodyFont: { size: 12 },
            padding: 12,
            cornerRadius: 8,
            displayColors: true,
        },
    },
}
</script>

<template>
    <div class="h-48 w-full sm:h-64">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>
