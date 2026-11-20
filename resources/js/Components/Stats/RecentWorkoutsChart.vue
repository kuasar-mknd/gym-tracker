<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { etiquetteDeDate } from '@/Utils/date'

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
        label: 'Volume',
        data: donneesChronologiques.value.map((d) => d.workout_volume || 0),
        backgroundColor: jeton('accent-primary'),
        borderRadius: 8,
        barPercentage: 0.6,
        borderWidth: 0,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.y} kg` },
}
</script>

<template>
    <BaseChart
        description="Volume des dernières séances"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-y="false"
    />
</template>
