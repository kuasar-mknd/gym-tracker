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
                backgroundColor: 'rgba(255, 255, 255, 0.5)',
                borderColor: 'rgba(255, 255, 255, 0.8)',
                borderWidth: 1,
                borderRadius: 8,
                hoverBackgroundColor: 'rgba(255, 255, 255, 0.8)',
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
            text: 'Volume Mensuel',
            color: 'rgba(255, 255, 255, 0.9)',
            font: {
                size: 16,
                weight: 'bold',
                family: 'sans-serif',
            },
            padding: {
                bottom: 20,
            },
        },
        tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.7)',
            titleColor: '#fff',
            bodyColor: '#fff',
            cornerRadius: 10,
            padding: 10,
            backdropFilter: 'blur(4px)',
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
            },
            ticks: {
                stepSize: 1,
                color: 'rgba(255, 255, 255, 0.7)',
                font: {
                    weight: 'bold',
                },
            },
            border: {
                display: false,
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: 'rgba(255, 255, 255, 0.7)',
                font: {
                    weight: 'bold',
                },
            },
            border: {
                display: false,
            },
        },
    },
}
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-6 shadow-lg backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 active:scale-[0.98]"
    >
        <div class="relative z-10 h-64 transition-transform duration-500 group-hover:scale-[1.02]">
            <Bar :data="chartData" :options="chartOptions" />
        </div>

        <!-- Subtle decorative glow -->
        <div
            class="pointer-events-none absolute -top-10 -right-10 h-32 w-32 rounded-full bg-white/10 blur-3xl transition-all duration-700 group-hover:bg-white/20"
        ></div>
    </div>
</template>
