<script setup>
import { jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { compte } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((d) => d.day))

const datasets = computed(() => [
    {
        label: 'Séances',
        data: props.data.map((d) => d.count),
        backgroundColor: jetonTransparent('accent-primary', 0.6),
        borderRadius: 6,
        barPercentage: 0.6,
        hoverBackgroundColor: jetonTransparent('accent-primary', 1),
        borderWidth: 1,
        borderColor: jetonTransparent('surface-card', 0.2),
    },
])

const infobulle = {
    accent: 'accent-primary',
    opaque: true,
    callbacks: { label: (context) => compte(context.parsed.y, 'séance', 'séances') },
}
</script>

<template>
    <BaseChart
        description="Nombre de séances par jour de la semaine"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="infobulle"
        entiers
    />
</template>
