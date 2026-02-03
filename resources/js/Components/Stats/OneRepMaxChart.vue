<!--
  OneRepMaxChart.vue - 1RM Progression Chart

  A line chart component using Chart.js to visualize the evolution
  of the user's Estimated One Rep Max (1RM) for a specific exercise.
  It renders a smooth line with a gradient fill under the curve.
-->
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
 * Component Props
 *
 * @property {Array} data - The dataset for the chart.
 * @property {string} data[].date - The X-axis label (date).
 * @property {number} data[].one_rep_max - The Y-axis value (estimated 1RM in kg).
 */
const props = defineProps({
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
                tension: 0.4,
                borderColor: '#FF0080',
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
            display: false,
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
                display: false,
            },
            ticks: {
                color: '#64748B',
                font: { size: 10, weight: 'bold' },
            },
        },
        y: {
            grid: {
                color: 'rgba(0, 0, 0, 0.03)',
            },
            ticks: {
                color: '#64748B',
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
