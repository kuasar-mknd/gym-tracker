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

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Durée (min)',
        data: props.data.map((item) => item.duration),
        backgroundColor: jetonTransparent('accent-primary', 0.6),
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-primary'),
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: {
        label: (context) => `${context.parsed.y} min`,
        afterLabel: (context) => props.data[context.dataIndex]?.name || '',
    },
}
</script>

<template>
    <BaseChart
        description="Durée des séances"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
    />
</template>
