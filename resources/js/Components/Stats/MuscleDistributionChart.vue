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

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.category),
        datasets: [
            {
                data: props.data.map((item) => item.volume),
                backgroundColor: [
                    '#FF5500', // Orange
                    '#FF0080', // Pink
                    '#8800FF', // Violet
                    '#00E5FF', // Cyan
                    '#00FF88', // Green
                    '#FFD600', // Yellow
                ],
                borderWidth: 0,
                hoverOffset: 15,
                borderRadius: 4,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '70%',
    plugins: {
        legend: {
            position: 'right',
            labels: {
                color: '#64748b',
                font: { size: 10, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            boxPadding: 8,
            borderWidth: 1,
            borderColor: 'rgba(0, 0, 0, 0.05)',
        },
    },
}
</script>

<template>
    <div class="h-52 w-full">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>
