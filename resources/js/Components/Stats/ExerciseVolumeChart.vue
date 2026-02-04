<script setup>
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const props = defineProps({
    sessions: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Clone and reverse to show chronological order (oldest to newest)
    const sortedSessions = [...props.sessions].reverse()

    const labels = sortedSessions.map((s) => s.formatted_date)
    const data = sortedSessions.map((session) => {
        return session.sets.reduce((total, set) => {
            const weight = parseFloat(set.weight) || 0
            const reps = parseFloat(set.reps) || 0
            return total + weight * reps
        }, 0)
    })

    return {
        labels,
        datasets: [
            {
                label: 'Volume (kg)',
                data: data,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, '#FF5500') // Electric Orange
                    gradient.addColorStop(1, '#DB2777') // Pink
                    return gradient
                },
                borderRadius: 6,
                barThickness: 'flex',
                maxBarThickness: 40,
                hoverBackgroundColor: '#8800FF',
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
        y: {
            beginAtZero: true,
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
                maxRotation: 45,
                minRotation: 0,
            },
        },
    },
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
                label: (context) => {
                    return `Volume: ${context.parsed.y.toLocaleString('fr-FR')} kg`
                },
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
