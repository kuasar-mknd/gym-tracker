<script setup>
import { computed } from 'vue'
import VolumeTrendChart from './VolumeTrendChart.vue'

const props = defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // History is typically sorted descending (newest first)
    // We reverse it to show chronological progression (oldest first)
    const sortedHistory = [...props.history].reverse()

    return sortedHistory.map((session) => {
        const volume = session.sets.reduce((total, set) => {
            return total + Number(set.weight) * Number(set.reps)
        }, 0)

        return {
            date: session.date,
            volume: Math.round(volume),
        }
    })
})
</script>

<template>
    <VolumeTrendChart :data="chartData" />
</template>
