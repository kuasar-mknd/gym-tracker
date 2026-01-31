<script setup>
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Colors for the buckets
const bucketColors = {
    '< 30 min': '#00E5FF', // cyan-pure
    '30-60 min': '#FF0080', // hot-pink
    '60-90 min': '#8800FF', // vivid-violet
    '90+ min': '#FF5500', // electric-orange
}

const chartData = computed(() => {
    // Expecting data to be [{ label: '< 30 min', count: 5 }, ...]
    const labels = props.data.map((item) => item.label)
    const counts = props.data.map((item) => item.count)
    const backgroundColor = labels.map((label) => bucketColors[label] || '#64748B')

    return {
        labels: labels,
        datasets: [
            {
                data: counts,
                backgroundColor: backgroundColor,
                borderWidth: 0,
                hoverOffset: 10,
                borderRadius: 4,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '65%',
    plugins: {
        legend: {
            position: 'right',
            labels: {
                color: '#64748B', // text-muted
                font: {
                    family: "'Space Grotesk', sans-serif",
                    size: 11,
                    weight: 'bold',
                },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#0F172A', // text-main
            titleFont: {
                family: "'Archivo', sans-serif",
                size: 13,
                weight: 'bold',
            },
            bodyColor: '#64748B', // text-muted
            bodyFont: {
                family: "'Inter', sans-serif",
                size: 12,
            },
            padding: 12,
            cornerRadius: 12,
            boxPadding: 6,
            borderColor: 'rgba(255, 255, 255, 0.5)',
            borderWidth: 1,
            displayColors: true,
            callbacks: {
                label: function (context) {
                    const label = context.label || ''
                    const value = context.raw || 0
                    const total = context.chart._metasets[context.datasetIndex].total
                    const percentage = Math.round((value / total) * 100) + '%'
                    return `${label}: ${value} (${percentage})`
                },
            },
        },
    },
    layout: {
        padding: 10,
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>
