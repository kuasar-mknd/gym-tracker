<script setup>
import { couleurDeCategorie } from '@/Utils/couleurs'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
    exercises: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Group exercises by category
    const counts = {}
    props.exercises.forEach((ex) => {
        const cat = ex.category || 'Autres'
        counts[cat] = (counts[cat] || 0) + 1
    })

    const labels = Object.keys(counts)
    const data = Object.values(counts)
    const backgroundColor = labels.map((cat) => couleurDeCategorie(cat))

    return {
        labels: labels,
        datasets: [
            {
                data: data,
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
