<script setup>
import '@/chartSetup'
import { Doughnut } from 'vue-chartjs'
import { computed } from 'vue'

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
                    '#38BDF8', // Sky 400 (Matin)
                    '#F59E0B', // Amber 500 (Après-midi)
                    '#8B5CF6', // Violet 500 (Soir)
                    '#312E81', // Indigo 900 (Nuit)
                ],
                hoverBackgroundColor: [
                    '#0EA5E9', // Sky 500
                    '#D97706', // Amber 600
                    '#7C3AED', // Violet 600
                    '#1E1B4B', // Indigo 950
                ],
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverOffset: 4,
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
                color: '#64748B',
                font: { size: 11, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#1e293b',
            bodyColor: '#1e293b',
            padding: 12,
            cornerRadius: 12,
            borderWidth: 1,
            borderColor: 'rgba(0, 0, 0, 0.05)',
            callbacks: {
                label: function (context) {
                    const value = context.raw
                    const total = context.chart._metasets[context.datasetIndex].total
                    const percentage = Math.round((value / total) * 100)
                    return ` ${value} séances (${percentage}%)`
                },
            },
        },
    },
}
</script>

<template>
    <div class="relative h-48 w-full">
        <Doughnut :data="chartData" :options="chartOptions" />
        <div class="pointer-events-none absolute inset-0 -ml-[120px] flex items-center justify-center">
            <span class="material-symbols-outlined text-text-muted/20 text-4xl">schedule</span>
        </div>
    </div>
</template>
