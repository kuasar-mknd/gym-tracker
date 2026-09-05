<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.label))

const datasets = computed(() => [
    {
        data: props.data.map((item) => item.count),
        backgroundColor: [
            jeton('accent-primary'), // Force / poids
            jeton('accent-info'), // Fréquence
            jeton('accent-tertiary'), // Volume
            jeton('accent-secondary'), // Mesure
            jeton('accent-state'), // Autres
        ],
    },
])
</script>

<template>
    <BaseChart type="doughnut" :labels="labels" :datasets="datasets" hauteur="h-52" :infobulle="{ boxPadding: 8 }" />
</template>
