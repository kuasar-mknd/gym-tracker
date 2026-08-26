<!--
  @component SessionPerformanceChart
  @description Displays a combination bar/line chart visualizing a user's workout performance over multiple sessions.
  It shows both the total volume (as bars) and the best 1RM (as a line) over time.

  @prop {Array} data - Required. An array of session objects containing 'formatted_date', 'sets', and 'best_1rm' properties.
-->
<script setup>
import { computed } from 'vue'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'
import { Bar } from 'vue-chartjs'
import { commonTooltipOptions } from './chartConfig'

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Title, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // History is desc, so reverse for chart
    const chronologicalData = [...props.data].reverse()

    const labels = chronologicalData.map((session) => session.formatted_date.split('/').slice(0, 2).join('/'))

    const volumeData = chronologicalData.map((session) =>
        session.sets.reduce((sum, set) => sum + (set.weight || 0) * (set.reps || 0), 0),
    )

    const oneRmData = chronologicalData.map((session) => session.best_1rm || 0)

    return {
        labels,
        datasets: [
            {
                type: 'line',
                label: 'Meilleur 1RM (kg)',
                data: oneRmData,
                borderColor: jeton('accent-tertiary'), // violet
                backgroundColor: jeton('accent-tertiary'),
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: jeton('surface-card'),
                pointBorderColor: jeton('accent-tertiary'),
                pointBorderWidth: 2,
                pointRadius: 4,
                yAxisID: 'y1',
                order: 1, // Draw line on top of bars
            },
            {
                type: 'bar',
                label: 'Volume Total (kg)',
                data: volumeData,
                backgroundColor: jetonTransparent('accent-primary', 0.2), // orange with opacity
                borderColor: jeton('accent-primary'),
                borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
                borderRadius: { topLeft: 6, topRight: 6 },
                yAxisID: 'y',
                order: 2,
            },
        ],
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
                usePointStyle: true,
                padding: 20,
                font: {
                    family: "'Nunito', sans-serif",
                    weight: 'bold',
                },
                color: jeton('text-muted'), // slate-500
            },
        },
        tooltip: {
            ...commonTooltipOptions,
            callbacks: {
                label: function (context) {
                    let label = context.dataset.label || ''
                    if (label) {
                        label += ': '
                    }
                    if (context.parsed.y !== null) {
                        label += context.parsed.y.toLocaleString()
                    }
                    return label
                },
            },
        },
    },
    scales: {
        x: {
            grid: {
                display: false,
                drawBorder: false,
            },
            ticks: {
                font: {
                    family: "'Nunito', sans-serif",
                    weight: 'bold',
                },
                color: jeton('text-muted'),
            },
        },
        y: {
            type: 'linear',
            display: true,
            position: 'left',
            grid: {
                color: jetonTransparent('surface-sunken', 0.5),
                drawBorder: false,
            },
            ticks: {
                font: {
                    family: "'Nunito', sans-serif",
                    weight: 'bold',
                },
                color: jeton('text-muted'),
                callback: function (value) {
                    if (value >= 1000) {
                        return (value / 1000).toFixed(1) + 'k'
                    }
                    return value
                },
            },
            title: {
                display: false,
                text: 'Volume',
            },
        },
        y1: {
            type: 'linear',
            display: true,
            position: 'right',
            grid: {
                drawOnChartArea: false, // only want the grid lines for one axis to show up
            },
            ticks: {
                font: {
                    family: "'Nunito', sans-serif",
                    weight: 'bold',
                },
                color: jeton('text-muted'),
            },
            title: {
                display: false,
                text: '1RM',
            },
        },
    },
}))
</script>

<template>
    <div class="h-full w-full">
        <Bar :data="chartData" :options="chartOptions" />
    </div>
</template>
