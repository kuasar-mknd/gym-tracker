<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import { volumeTooltipCallback } from './chartConfig'
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
        label: 'Volume',
        data: props.data.map((item) => item.volume),
        backgroundColor: jeton('accent-primary'),
        borderRadius: 4,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])

const infobulle = { accent: 'accent-primary', callbacks: { label: volumeTooltipCallback } }
</script>

<template>
    <BaseChart
        description="Volume par séance"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48 sm:h-64"
        :infobulle="infobulle"
    />
</template>
