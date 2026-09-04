<!--
  @component SessionPerformanceChart
  @description Displays a combination bar/line chart visualizing a user's workout performance over multiple sessions.
  It shows both the total volume (as bars) and the best 1RM (as a line) over time.

  @prop {Array} data - Required. An array of session objects containing 'formatted_date', 'sets', and 'best_1rm' properties.
-->
<script setup>
import { computed } from 'vue'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// History is desc, so reverse for chart
const seances = computed(() => [...props.data].reverse())

const labels = computed(() => seances.value.map((session) => session.formatted_date.split('/').slice(0, 2).join('/')))

const datasets = computed(() => [
    {
        type: 'line',
        label: 'Meilleur 1RM (kg)',
        data: seances.value.map((session) => session.best_1rm || 0),
        borderColor: jeton('accent-tertiary'), // violet
        backgroundColor: jeton('accent-tertiary'),
        borderWidth: 3,
        tension: 0.4,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-tertiary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        yAxisID: 'y1',
        order: 1, // Draw line on top of bars
    },
    {
        type: 'bar',
        label: 'Volume Total (kg)',
        data: seances.value.map((session) =>
            session.sets.reduce((sum, set) => sum + (set.weight || 0) * (set.reps || 0), 0),
        ),
        backgroundColor: jetonTransparent('accent-primary', 0.2), // orange with opacity
        borderColor: jeton('accent-primary'),
        borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
        borderRadius: { topLeft: 6, topRight: 6 },
        yAxisID: 'y',
        order: 2,
    },
])

const infobulle = {
    displayColors: true,
    callbacks: {
        label: function (context) {
            let label = context.dataset.label || ''
            if (label) {
                label += ': '
            }
            if (context.parsed.y !== null) {
                label += context.parsed.y.toLocaleString()
            }
            return label
        },
    },
}

const axeY = {
    position: 'left',
    grid: { color: jetonTransparent('surface-sunken', 0.5) },
    ticks: {
        callback: function (value) {
            if (value >= 1000) {
                return (value / 1000).toFixed(1) + 'k'
            }
            return value
        },
    },
}

// Le 1RM ne part pas de zéro : ancré, il écraserait la courbe contre l'axe.
const axeY1 = { position: 'right', beginAtZero: false, grid: { drawOnChartArea: false } }
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :legende="{ position: 'top' }"
        :infobulle="infobulle"
        :interaction="{ mode: 'index', intersect: false }"
        :axe-y="axeY"
        :axe-y1="axeY1"
    />
</template>
