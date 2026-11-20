<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { compte } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Doses prises',
        data: props.data.map((item) => item.count),
        backgroundColor: jetonTransparent('accent-primary', 0.7),
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-primary'),
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => compte(context.raw, 'dose', 'doses') },
}
</script>

<template>
    <BaseChart
        description="Doses de compléments prises par jour"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :axe-x="{ ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } }"
        :axe-y="{ ticks: { stepSize: 1, precision: 0 } }"
    />
</template>
