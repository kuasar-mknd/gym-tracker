<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import { formatVolumeTick } from '@/Utils/volumeAxis'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
    hauteur: { type: String, default: 'h-48' },
})

const labels = computed(() => props.data.map((item) => item.date))

const datasets = computed(() => [
    {
        label: 'Volume',
        data: props.data.map((item) => item.volume),
        backgroundColor: jeton('accent-primary'),
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-tertiary'),
    },
])
</script>

<template>
    <BaseChart
        description="Évolution du volume"
        :labels="labels"
        :datasets="datasets"
        :hauteur="hauteur"
        :infobulle="{ accent: 'accent-primary' }"
        :axe-y="{ ticks: { callback: formatVolumeTick } }"
    />
</template>
