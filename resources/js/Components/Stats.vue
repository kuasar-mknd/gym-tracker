<script setup>
import { computed } from 'vue'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { Bar } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const props = defineProps({
    workouts: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Process workouts to get count per month
    const months = {}
    const today = new Date()

    // Initialize last 6 months
    for (let i = 5; i >= 0; i--) {
        const d = new Date(today.getFullYear(), today.getMonth() - i, 1)
        const key = d.toLocaleString('default', { month: 'short', year: 'numeric' })
        months[key] = 0
    }

    props.workouts.forEach((workout) => {
        const date = new Date(workout.started_at)
        const key = date.toLocaleString('default', { month: 'short', year: 'numeric' })
        if (months.hasOwnProperty(key)) {
            months[key]++
        }
    })

    return {
        labels: Object.keys(months),
        datasets: [
            {
                label: 'Workouts',
                data: Object.values(months),
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1,
                borderRadius: 4,
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
        title: {
            display: true,
            text: 'Monthly Workout Volume',
            color: '#4B5563', // gray-600
            font: {
                size: 16,
                weight: 'bold',
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(0, 0, 0, 0.05)',
            },
            ticks: {
                stepSize: 1,
            },
        },
        x: {
            grid: {
                display: false,
            },
        },
    },
}
</script>

<template>
    <div class="stats-container relative overflow-hidden p-6">
        <div class="absolute inset-0 rounded-xl border border-white/50 bg-white/40 shadow-lg backdrop-blur-md"></div>
        <div class="relative z-10 h-64">
            <Bar :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>

<style scoped>
.stats-container {
    /* Liquid Glass Aesthetic */
    background: rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
}
</style>
