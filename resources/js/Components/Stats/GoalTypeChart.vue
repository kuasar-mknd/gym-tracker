<script setup>
import { Doughnut } from 'vue-chartjs'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
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
        labels: props.data.map((item) => item.label),
        datasets: [
            {
                data: props.data.map((item) => item.count),
                backgroundColor: [
                    jeton('accent-primary'), // Electric Orange (Force/Weight)
                    jeton('accent-info'), // Cyan Pure (Frequency)
                    jeton('accent-tertiary'), // Vivid Violet (Volume)
                    jeton('accent-secondary'), // Hot Pink (Measurement)
                    jeton('accent-state'), // Neon Green (Others)
                ],
                borderWidth: 0,
                hoverOffset: 15,
                borderRadius: 4,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '70%',
    plugins: {
        legend: {
            position: 'right',
            labels: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.9),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            padding: 12,
            cornerRadius: 12,
            boxPadding: 8,
            borderWidth: 1,
            borderColor: jetonTransparent('shadow-cast', 0.05),
        },
    },
}))
</script>

<template>
    <div class="h-52 w-full">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>
