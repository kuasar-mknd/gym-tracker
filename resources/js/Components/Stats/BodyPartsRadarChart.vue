<script setup>
import { Radar } from 'vue-chartjs'
import { Chart as ChartJS, RadialLinearScale, PointElement, LineElement, Filler, Tooltip, Legend } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(RadialLinearScale, PointElement, LineElement, Filler, Tooltip, Legend)

const props = defineProps({
    data: {
        type: Array,
        required: true,
        // Array of objects like: { part: 'Waist', current: 80.5, diff: -2.0, date: '...', unit: 'cm' }
    },
})

const chartData = computed(() => {
    // We only want to plot measurements with the same unit to make the radar chart meaningful,
    // or we just assume they are mostly cm and plot them anyway since it's an overview.
    // Assuming we plot the 'current' value for each 'part'.

    // Sort data to ensure consistent ordering
    const sortedData = [...props.data].sort((a, b) => a.part.localeCompare(b.part))

    const labels = sortedData.map((item) => item.part)
    const values = sortedData.map((item) => item.current)

    return {
        labels,
        datasets: [
            {
                label: 'Mesures actuelles',
                backgroundColor: 'rgba(139, 92, 246, 0.2)', // vivid-violet with opacity
                borderColor: '#8B5CF6',
                pointBackgroundColor: '#fff',
                pointBorderColor: '#8B5CF6',
                pointHoverBackgroundColor: '#8B5CF6',
                pointHoverBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6,
                borderWidth: 2,
                data: values,
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
            borderColor: 'rgba(139, 92, 246, 0.2)',
            borderWidth: 1,
            padding: 10,
            displayColors: false,
            callbacks: {
                label: (context) => {
                    const dataItem = props.data.find((d) => d.part === context.label)
                    const unit = dataItem ? dataItem.unit : ''
                    return `${context.parsed.r} ${unit}`
                },
            },
        },
    },
    scales: {
        r: {
            angleLines: {
                color: 'rgba(0, 0, 0, 0.1)'
            },
            grid: {
                color: 'rgba(0, 0, 0, 0.05)'
            },
            pointLabels: {
                color: '#94a3b8',
                font: {
                    size: 11,
                    weight: 'bold',
                    family: 'sans-serif',
                },
            },
            ticks: {
                display: false,
                backdropColor: 'transparent',
            },
        },
    },
}
</script>

<template>
    <div class="h-64 w-full">
        <Radar :data="chartData" :options="chartOptions" />
    </div>
</template>
