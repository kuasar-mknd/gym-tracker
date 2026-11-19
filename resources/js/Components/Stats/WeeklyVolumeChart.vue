<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((d) => d.day_label))

const datasets = computed(() => [
    {
        label: 'Volume',
        data: props.data.map((d) => d.volume),
        fill: true,
        backgroundColor: jetonTransparent('accent-primary', 0.08),
        borderColor: jeton('accent-primary'),
        borderWidth: 2,
        tension: 0.4,
        pointBackgroundColor: jeton('surface-card'),
        pointBorderColor: jeton('accent-primary'),
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
    },
])

const infobulle = { accent: 'accent-primary', callbacks: { label: (context) => `${context.parsed.y} kg` } }

const marges = { layout: { padding: { left: -10, right: -10, bottom: 0, top: 10 } } }
</script>

<template>
    <BaseChart
        description="Volume soulevé chaque jour de la semaine en cours"
        type="line"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { padding: 10 } }"
        :axe-y="{ display: false, beginAtZero: true }"
        :interaction="{ mode: 'index', intersect: false }"
        :options="marges"
    />
</template>
