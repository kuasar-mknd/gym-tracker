<script setup>
import { Radar } from 'vue-chartjs'
import { Chart as ChartJS, RadialLinearScale, PointElement, LineElement, Filler, Tooltip, Legend } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(RadialLinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = defineProps({
    achievements: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    const categories = [...new Set(props.achievements.map((a) => a.category))]
    // Capitalize for labels
    const labels = categories.map((c) => c.charAt(0).toUpperCase() + c.slice(1))

    const masteryData = categories.map((cat) => {
        const inCat = props.achievements.filter((a) => a.category === cat)
        const total = inCat.length
        const unlocked = inCat.filter((a) => a.is_unlocked).length
        return total > 0 ? Math.round((unlocked / total) * 100) : 0
    })

    return {
        labels: labels,
        datasets: [
            {
                label: 'Mastery (%)',
                data: masteryData,
                backgroundColor: 'rgba(6, 182, 212, 0.2)', // Cyan-500 low opacity
                borderColor: '#06b6d4', // Cyan-500
                borderWidth: 2,
                pointBackgroundColor: '#06b6d4',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#06b6d4',
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
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            callbacks: {
                label: (context) => `${context.raw}% Unlocked`,
            },
        },
    },
    scales: {
        r: {
            angleLines: {
                color: 'rgba(255, 255, 255, 0.1)',
            },
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
            },
            pointLabels: {
                color: '#94a3b8', // Slate-400
                font: {
                    size: 12,
                    weight: 'bold',
                },
            },
            ticks: {
                display: false, // Hide the concentric number labels
                backdropColor: 'transparent',
            },
            suggestedMin: 0,
            suggestedMax: 100,
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Radar :data="chartData" :options="chartOptions" />
    </div>
</template>
