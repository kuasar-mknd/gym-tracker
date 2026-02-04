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
import { computed } from 'vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

/**
 * OneRepMaxChart Component
 *
 * Renders a line chart visualizing the progression of the Estimated 1RM (One Rep Max) over time.
 * Uses Chart.js with a custom "Liquid Glass" styling (gradient fill, custom tooltips).
 *
 * @component
 */

const props = defineProps({
    /**
     * The dataset to visualize.
     * Expects an array of objects sorted by date.
     *
     * Structure:
     * [
     *   {
     *     date: string,        // Formatted date (e.g., "12/05") for X-axis labels
     *     one_rep_max: float,  // The estimated 1RM value in kg
     *     full_date?: string,  // Optional full date for tooltip details
     *   },
     *   ...
     * ]
     */
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.date),
        datasets: [
            {
                label: 'Estimé 1RM (kg)',
                data: props.data.map((item) => item.one_rep_max),
                fill: true,
                tension: 0.4, // Smooth curve
                borderColor: '#FF0080',
                // Gradient background for the "Liquid Glass" effect
                backgroundColor: (context) => {
                    const chart = context.chart
                    const { ctx, chartArea } = chart
                    if (!chartArea) return null
                    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, 'rgba(255, 0, 128, 0.2)')
                    gradient.addColorStop(1, 'rgba(255, 0, 128, 0)')
                    return gradient
                },
                borderWidth: 3,
                pointRadius: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#FF0080',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#FF0080',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false, // Hide legend as we have a single dataset
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            borderWidth: 1,
            borderColor: 'rgba(255, 0, 128, 0.1)',
        },
    },
    scales: {
        x: {
            grid: {
                display: false, // Clean look without vertical grid lines
            },
            ticks: {
                color: '#64748B', // Slate-500
                font: { size: 10, weight: 'bold' },
            },
        },
        y: {
            grid: {
                color: 'rgba(0, 0, 0, 0.03)', // Subtle horizontal lines
            },
            ticks: {
                color: '#64748B', // Slate-500
                font: { size: 10, weight: 'bold' },
            },
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
