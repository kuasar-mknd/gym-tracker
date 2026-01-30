<script setup>
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'
import { computed, ref } from 'vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const metrics = [
    { value: 'mood_score', label: 'Humeur', color: '#F472B6', labelSuffix: '/5' }, // Pink
    { value: 'sleep_quality', label: 'Sommeil', color: '#818CF8', labelSuffix: '/5' }, // Indigo
    { value: 'energy_level', label: 'Énergie', color: '#FACC15', labelSuffix: '/10' }, // Yellow
    { value: 'stress_level', label: 'Stress', color: '#FB923C', labelSuffix: '/10' }, // Orange
    { value: 'motivation_level', label: 'Motivation', color: '#F43F5E', labelSuffix: '/10' }, // Rose
    { value: 'nutrition_score', label: 'Diète', color: '#34D399', labelSuffix: '/5' }, // Emerald
    { value: 'training_intensity', label: 'Intensité', color: '#EF4444', labelSuffix: '/10' }, // Red
]

const selectedMetric = ref('mood_score')

const currentMetricConfig = computed(() => {
    return metrics.find((m) => m.value === selectedMetric.value)
})

const chartData = computed(() => {
    // Sort data by date ascending
    const sortedData = [...props.data].sort((a, b) => new Date(a.date) - new Date(b.date))

    return {
        labels: sortedData.map((item) => {
            const date = new Date(item.date)
            return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
        }),
        datasets: [
            {
                label: currentMetricConfig.value.label,
                data: sortedData.map((item) => item[selectedMetric.value]),
                fill: true,
                tension: 0.4,
                borderColor: currentMetricConfig.value.color,
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    // Convert hex to rgba for gradient
                    const hex = currentMetricConfig.value.color.replace('#', '')
                    const r = parseInt(hex.substring(0, 2), 16)
                    const g = parseInt(hex.substring(2, 4), 16)
                    const b = parseInt(hex.substring(4, 6), 16)

                    gradient.addColorStop(0, `rgba(${r}, ${g}, ${b}, 0.2)`)
                    gradient.addColorStop(1, `rgba(${r}, ${g}, ${b}, 0)`)
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: currentMetricConfig.value.color,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: currentMetricConfig.value.color,
                pointHoverBorderWidth: 3,
            },
        ],
    }
})

const chartOptions = computed(() => {
    return {
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
                displayColors: false,
                borderWidth: 1,
                borderColor: 'rgba(0, 0, 0, 0.05)',
                callbacks: {
                    label: (context) => `${context.parsed.y} ${currentMetricConfig.value.labelSuffix}`,
                },
            },
        },
        scales: {
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    color: '#94a3b8',
                    font: { size: 10 },
                    maxRotation: 45,
                    minRotation: 0,
                },
            },
            y: {
                beginAtZero: true,
                suggestedMax: currentMetricConfig.value.labelSuffix === '/5' ? 5 : 10,
                ticks: {
                    color: '#94a3b8',
                    font: { size: 10, weight: 'bold' },
                    stepSize: 1,
                },
                grid: {
                    color: 'rgba(255, 255, 255, 0.1)',
                    borderDash: [5, 5],
                },
                border: {
                    display: false,
                },
            },
        },
    }
})
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Metric Selector -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="metric in metrics"
                :key="metric.value"
                @click="selectedMetric = metric.value"
                :class="[
                    'rounded-lg px-3 py-1.5 text-xs font-bold tracking-wider uppercase transition-all',
                    selectedMetric === metric.value
                        ? 'scale-105 text-white shadow-lg'
                        : 'text-text-muted hover:text-text-main bg-white/50 hover:bg-white/80',
                ]"
                :style="selectedMetric === metric.value ? { backgroundColor: metric.color } : {}"
            >
                {{ metric.label }}
            </button>
        </div>

        <!-- Chart -->
        <div class="h-64 w-full">
            <Line v-if="data.length > 0" :data="chartData" :options="chartOptions" />
            <div v-else class="text-text-muted flex h-full items-center justify-center">
                Pas assez de données pour afficher le graphique
            </div>
        </div>
    </div>
</template>

<style scoped>
canvas {
    filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
}
</style>
