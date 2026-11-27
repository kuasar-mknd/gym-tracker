<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { compte } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((d) => d.date))

const datasets = computed(() => [
    {
        label: 'Séries',
        data: props.data.map((d) => d.sets),
        backgroundColor: jeton('accent-secondary'),
        borderRadius: 4,
        barPercentage: 0.5,
        borderWidth: 0,
    },
])

const infobulle = {
    accent: 'accent-secondary',
    opaque: true,
    callbacks: { label: (context) => compte(context.parsed.y, 'série', 'séries') },
}
</script>

<template>
    <BaseChart
        description="Nombre de séries par séance"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-full"
        :infobulle="infobulle"
        entiers
    />
</template>
