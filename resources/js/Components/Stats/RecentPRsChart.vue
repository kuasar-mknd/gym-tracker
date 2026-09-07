<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { poids, volume } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() =>
    props.data.map((pr) => {
        let title = pr.exercise?.name || 'PR'
        // truncate if too long
        if (title.length > 15) {
            title = title.substring(0, 12) + '...'
        }
        return title
    }),
)

const datasets = computed(() => [
    {
        label: 'Valeur (kg/reps)',
        data: props.data.map((pr) => pr.value),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
            gradient.addColorStop(0, jeton('accent-primary')) // electric-orange
            gradient.addColorStop(1, jeton('accent-warning')) // yellow

            return gradient
        },
        borderRadius: 8,
        barPercentage: 0.6,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'), // vivid-violet
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: {
        label: (context) => {
            const pr = props.data[context.dataIndex]
            // `max_volume_set` vaut poids × répétitions : c'est un volume en
            // kilos, et l'infobulle l'annonçait en répétitions.
            return pr.type === 'max_volume_set' ? volume(context.parsed.y) : poids(context.parsed.y)
        },
    },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { maxRotation: 45, minRotation: 0 } }"
        :axe-y="false"
    />
</template>
