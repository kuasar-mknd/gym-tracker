<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // History is desc, so reverse for chart chronological order
    const sessions = [...props.data].reverse()
    const labels = sessions.map((session) => session.formatted_date.split('/').slice(0, 2).join('/'))

    // Find maximum number of sets in the history, but limit to first 3 sets to keep the chart clean
    const maxSets = Math.min(3, Math.max(...sessions.map((s) => s.sets.length)))

    const datasets = []

    // Liquid Glass inspired colors for up to 3 sets
    const setColors = [
        jeton('accent-primary'), // electric-orange
        jeton('accent-info'), // cyan-pure
        jeton('accent-tertiary'), // vivid-violet
    ]

    for (let i = 0; i < maxSets; i++) {
        datasets.push({
            label: `Série ${i + 1}`,
            data: sessions.map((session) => {
                const set = session.sets[i]
                return set && parseFloat(set.weight) > 0 ? parseFloat(set.weight) : null
            }),
            borderColor: setColors[i % setColors.length],
            backgroundColor: setColors[i % setColors.length],
            borderWidth: 3,
            tension: 0.4,
            spanGaps: true,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointBackgroundColor: jeton('surface-card'),
            pointBorderColor: setColors[i % setColors.length],
            pointBorderWidth: 2,
        })
    }

    return {
        labels,
        datasets,
    }
})

const infobulle = {
    accent: 'shadow-cast',
    opaque: true,
    displayColors: true,
    callbacks: { label: (context) => `${context.dataset.label}: ${context.parsed.y} kg` },
}
</script>

<template>
    <BaseChart
        type="line"
        :labels="chartData.labels"
        :datasets="chartData.datasets"
        hauteur="h-64"
        :legende="{ position: 'top', labels: { boxWidth: 6 } }"
        :infobulle="infobulle"
        :interaction="{ mode: 'index', intersect: false }"
    />
</template>
