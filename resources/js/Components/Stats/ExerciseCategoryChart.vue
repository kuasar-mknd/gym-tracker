<script setup>
import { couleurDeCategorie } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    exercises: {
        type: Array,
        required: true,
    },
})

// Group exercises by category
const comptes = computed(() => {
    const counts = {}
    props.exercises.forEach((ex) => {
        const cat = ex.category || 'Autres'
        counts[cat] = (counts[cat] || 0) + 1
    })

    return counts
})

const labels = computed(() => Object.keys(comptes.value))

const datasets = computed(() => [
    {
        data: Object.values(comptes.value),
        backgroundColor: labels.value.map((cat) => couleurDeCategorie(cat)),
        borderWidth: 0,
        hoverOffset: 10,
        borderRadius: 4,
    },
])

const infobulle = {
    callbacks: {
        label: function (context) {
            const label = context.label || ''
            const value = context.raw || 0
            const total = context.chart._metasets[context.datasetIndex].total
            const percentage = Math.round((value / total) * 100) + '%'
            return `${label}: ${value} (${percentage})`
        },
    },
}
</script>

<template>
    <BaseChart type="doughnut" :labels="labels" :datasets="datasets" hauteur="h-48" :infobulle="infobulle" />
</template>
