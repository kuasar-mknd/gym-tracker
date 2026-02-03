<script setup>
/**
 * WeeklyVolumeChart.vue
 *
 * Displays a line chart representing the user's volume (weight * reps) trend over the current week.
 *
 * Design:
 * - Uses the "Liquid Glass" aesthetic with vibrant gradients.
 * - Fill gradient: Vertical (Top to Bottom) fading orange.
 * - Border gradient: Horizontal (Left to Right) shifting from Orange to Pink to Violet.
 * - Minimalist axis (no grid lines, hidden Y axis).
 *
 * Dependencies:
 * - vue-chartjs (Line chart)
 * - chart.js (Core library)
 */
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

/**
 * Component Props
 *
 * @property {Array} data - Array of daily data points.
 * @property {string} data[].day_label - The label for the X-axis (e.g., "Lun", "Mar").
 * @property {number} data[].volume - The total volume lifted for that day.
 */
const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

/**
 * Computed Chart Data Configuration
 *
 * Constructs the Chart.js data object.
 * Defines the dataset including:
 * - Dynamic background gradient (fill).
 * - Dynamic border gradient (stroke).
 * - Point styling.
 */
const chartData = computed(() => {
    const labels = props.data.map((d) => d.day_label)
    const volumes = props.data.map((d) => d.volume)

    return {
        labels,
        datasets: [
            {
                label: 'Volume',
                data: volumes,
                fill: true,
                /**
                 * Background Gradient (Vertical)
                 * Fades from opaque Orange to transparent.
                 */
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(255, 85, 0, 0.2)') // Electric Orange
                    gradient.addColorStop(1, 'rgba(255, 85, 0, 0)')

                    return gradient
                },
                /**
                 * Border Gradient (Horizontal)
                 * Shifts from Orange -> Pink -> Violet.
                 */
                borderColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null

                    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
                    gradient.addColorStop(0, '#FF5500') // Electric Orange
                    gradient.addColorStop(0.5, '#FF0080') // Hot Pink
                    gradient.addColorStop(1, '#8800FF') // Vivid Violet

                    return gradient
                },
                borderWidth: 4,
                tension: 0.4, // Smooth curves
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: '#FF0080',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            },
        ],
    }
})

/**
 * Chart.js Options Configuration
 *
 * Customizes the chart appearance:
 * - Disables legend.
 * - Custom tooltip with volume formatting.
 * - Hides grid lines and Y-axis labels for a cleaner look.
 */
const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            borderColor: 'rgba(255, 85, 0, 0.1)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.parsed.y} kg`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                color: '#94a3b8',
                font: { size: 10, weight: 'bold', family: 'sans-serif' },
                padding: 10,
            },
            border: { display: false },
        },
        y: {
            display: false, // Hide Y axis
            beginAtZero: true,
        },
    },
    interaction: {
        mode: 'index',
        intersect: false,
    },
    layout: {
        padding: {
            left: -10,
            right: -10,
            bottom: 0,
            top: 10,
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
