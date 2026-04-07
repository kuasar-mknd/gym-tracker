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
    const labels = props.data.map((pr) => {
        let title = pr.exercise?.name || 'PR'
        // truncate if too long
        if (title.length > 15) {
            title = title.substring(0, 12) + '...'
        }
        return title
    })

    const prValues = props.data.map((pr) => pr.value)

    return {
        labels,
        datasets: [
            {
                label: 'Valeur (kg/reps)',
                data: prValues,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
                    gradient.addColorStop(0, '#FF5500') // electric-orange
                    gradient.addColorStop(1, '#FFCC00') // yellow

                    return gradient
                },
                borderRadius: 8,
                barPercentage: 0.6,
                borderWidth: 0,
                hoverBackgroundColor: '#8800FF', // vivid-violet
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
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(255, 85, 0, 0.2)',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => {
                    const pr = props.data[context.dataIndex]
                    const unit = pr.type === 'max_volume_set' ? ' reps' : ' kg'
                    return `${context.parsed.y}${unit}`
                },
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                maxRotation: 45,
                minRotation: 0,
            },
            border: { display: false },
        },
        y: {
            display: false,
            beginAtZero: true,
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
