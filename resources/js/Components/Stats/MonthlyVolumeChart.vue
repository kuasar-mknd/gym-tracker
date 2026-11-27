<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import { formatVolumeTick } from '@/Utils/volumeAxis'
import BaseChart from './BaseChart.vue'
import { volume } from '@/Utils/nombre'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.month))

const datasets = computed(() => [
    {
        label: 'Volume',
        data: props.data.map((item) => item.volume),
        backgroundColor: jeton('accent-primary'),
        borderRadius: 4,
    },
])

const infobulle = {
    accent: 'accent-primary',
    callbacks: { label: (context) => volume(context.raw) },
}
</script>

<template>
    <BaseChart
        description="Volume soulevé par mois"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48 sm:h-64"
        :infobulle="infobulle"
        :axe-y="{ ticks: { callback: formatVolumeTick } }"
    />
</template>
