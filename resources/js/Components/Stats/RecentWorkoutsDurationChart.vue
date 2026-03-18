<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Filler,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Filler)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Reverse the array so chronological order is left-to-right
    const workouts = [...props.data].reverse()

    return {
        labels: workouts.map((w) =>
            new Date(w.started_at).toLocaleDateString('fr-FR', {
                day: 'numeric',
                month: 'short',
            }),
        ),
        datasets: [
            {
                label: 'Durée (min)',
                data: workouts.map((w) => {
                    if (w.duration_minutes) return w.duration_minutes
                    if (w.ended_at && w.started_at) {
                        return Math.round((new Date(w.ended_at) - new Date(w.started_at)) / 60000)
                    }
                    return 0
                }),
                borderColor: '#ec4899', // hot-pink
                backgroundColor: 'rgba(236, 72, 153, 0.2)',
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#ec4899',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(236, 72, 153, 0.2)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} min`,
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
                drawBorder: false,
            },
            ticks: {
                color: 'rgba(255, 255, 255, 0.7)',
                font: { size: 10, weight: 'bold' },
            },
        },
        x: {
            grid: { display: false, drawBorder: false },
            ticks: {
                color: 'rgba(255, 255, 255, 0.7)',
                font: { size: 10, weight: 'bold' },
            },
        },
    },
}
</script>

<template>
    <div class="h-full w-full" style="filter: drop-shadow(0 4px 6px rgba(236, 72, 153, 0.2))">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
