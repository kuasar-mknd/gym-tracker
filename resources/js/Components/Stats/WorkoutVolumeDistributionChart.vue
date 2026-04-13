<script setup>
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
    workout: {
        type: Object,
        required: true,
    },
})

const chartData = computed(() => {
    // Extract workout lines
    const lines = props.workout?.workout_lines || []

    // Calculate volume per exercise
    const exerciseVolumes = lines.map(line => {
        const volume = (line.sets || []).reduce((sum, set) => {
            return sum + (parseFloat(set.weight) || 0) * (parseInt(set.reps) || 0)
        }, 0)
        return {
            name: line.exercise?.name || 'Unknown',
            volume: volume
        }
    }).filter(item => item.volume > 0)

    return {
        labels: exerciseVolumes.map(item => item.name),
        datasets: [
            {
                data: exerciseVolumes.map(item => item.volume),
                backgroundColor: [
                    '#FF5500', // electric-orange
                    '#FF0080', // hot-pink
                    '#8800FF', // vivid-violet
                    '#00E5FF', // cyan
                    '#00FF88', // neon-green
                    '#FFD600', // yellow
                    '#FF3366', // rose
                    '#33CCFF', // light blue
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
            callbacks: {
                label: function(context) {
                    let label = context.label || '';
                    if (label) {
                        label += ': ';
                    }
                    if (context.parsed !== null) {
                        label += context.parsed + ' kg';
                    }
                    return label;
                }
            }
        },
    },
}
</script>

<template>
    <div class="h-48 w-full">
        <Doughnut v-if="chartData.datasets[0].data.length > 0" :data="chartData" :options="chartOptions" />
        <div v-else class="text-text-muted/50 flex h-full items-center justify-center font-medium text-sm">
            Ajoutez des séries avec du poids
        </div>
    </div>
</template>
