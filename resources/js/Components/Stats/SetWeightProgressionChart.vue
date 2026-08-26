<script setup>
import { Line } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // History is desc, so reverse for chart chronological order
    const sessions = [...props.data].reverse()
    const labels = sessions.map((session) => session.formatted_date.split('/').slice(0, 2).join('/'))

    // Find maximum number of sets in the history, but limit to first 3 sets to keep the chart clean
    const maxSets = Math.min(3, Math.max(...sessions.map((s) => s.sets.length)))

    const datasets = []

    // Liquid Glass inspired colors for up to 3 sets
    const setColors = [
        jeton('accent-primary'), // electric-orange
        jeton('accent-info'), // cyan-pure
        jeton('accent-tertiary'), // vivid-violet
    ]

    for (let i = 0; i < maxSets; i++) {
        datasets.push({
            label: `Série ${i + 1}`,
            data: sessions.map((session) => {
                const set = session.sets[i]
                return set && parseFloat(set.weight) > 0 ? parseFloat(set.weight) : null
            }),
            borderColor: setColors[i % setColors.length],
            backgroundColor: setColors[i % setColors.length],
            borderWidth: 3,
            tension: 0.4,
            spanGaps: true,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointBackgroundColor: '#fff',
            pointBorderColor: setColors[i % setColors.length],
            pointBorderWidth: 2,
        })
    }

    return {
        labels,
        datasets,
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index',
        intersect: false,
    },
    plugins: {
        legend: {
            display: true,
            position: 'top',
            labels: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                usePointStyle: true,
                boxWidth: 6,
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.95),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            borderColor: jetonTransparent('shadow-cast', 0.1),
            borderWidth: 1,
            cornerRadius: 12,
            padding: 12,
            callbacks: {
                label: (context) => `${context.dataset.label}: ${context.parsed.y} kg`,
            },
        },
    },
    scales: {
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
        },
        y: {
            grid: {
                color: jetonTransparent('shadow-cast', 0.05),
            },
            ticks: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
            },
        },
    },
}))
</script>

<template>
    <div class="h-64 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
