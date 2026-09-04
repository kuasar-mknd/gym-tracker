<script setup>
import { jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.month))

const datasets = computed(() => [
    {
        label: 'Séances',
        backgroundColor: jetonTransparent('accent-info', 0.6),
        borderRadius: 4,
        data: props.data.map((item) => item.count),
    },
])
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48 sm:h-64"
        :infobulle="{ accent: 'accent-info' }"
        :axe-y="{ ticks: { stepSize: 1 } }"
    />
</template>
