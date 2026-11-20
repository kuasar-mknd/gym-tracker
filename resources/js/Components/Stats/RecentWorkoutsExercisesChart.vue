<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { etiquetteDeDate } from '@/Utils/date'
import { compte } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Reverse the data so it reads chronologically from left to right
const donneesChronologiques = computed(() => [...props.data].reverse())

const labels = computed(() =>
    donneesChronologiques.value.map((d) => {
        const date = new Date(d.started_at)
        return etiquetteDeDate(date)
    }),
)

const datasets = computed(() => [
    {
        label: 'Exercices',
        data: donneesChronologiques.value.map((d) => d.workout_lines_count || 0),
        backgroundColor: jeton('accent-state'),
        borderRadius: 8,
        barPercentage: 0.6,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])

const infobulle = {
    accent: 'accent-state',
    opaque: true,
    callbacks: { label: (context) => compte(context.parsed.y, 'exercice', 'exercices') },
}
</script>

<template>
    <BaseChart
        description="Nombre d'exercices des dernières séances"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-y="{ display: false, ticks: { stepSize: 1 } }"
    />
</template>
