<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { grille } from './chartConfig'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const datasets = computed(() => [
    {
        label: 'Séries',
        data: props.data,
        backgroundColor: jetonTransparent('accent-primary', 0.6), // Electric Orange with opacity
        borderColor: jeton('accent-primary'),
        borderWidth: 1,
        pointRadius: 5,
        pointHoverRadius: 8,
    },
])

const titre = (text) => ({ display: true, text, color: jeton('text-muted'), font: { size: 10, weight: 'bold' } })

const infobulle = {
    opaque: true,
    callbacks: { label: (context) => `${context.parsed.x} kg × ${context.parsed.y} reps` },
}
</script>

<template>
    <BaseChart
        type="scatter"
        :datasets="datasets"
        hauteur="h-full"
        lueur="accent-primary"
        :infobulle="infobulle"
        :axe-x="{ title: titre('Poids (kg)'), grid: grille() }"
        :axe-y="{ title: titre('Répétitions'), beginAtZero: true }"
    />
</template>
