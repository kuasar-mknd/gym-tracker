<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
    goal: {
        type: Number,
        default: 2500,
    },
})

const labels = computed(() => props.data.map((item) => item.day_name.substring(0, 3)))

const datasets = computed(() => [
    {
        label: 'Volume (ml)',
        data: props.data.map((item) => item.total),
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            gradient.addColorStop(0, jeton('accent-info')) // Blue-500
            gradient.addColorStop(1, jetonTransparent('accent-info', 0.55)) // Blue-300
            return gradient
        },
        borderRadius: 6,
        hoverBackgroundColor: jeton('accent-info'), // Blue-600
    },
])

/**
 * computed, not a plain object. Evaluated once at setup, the axis maximum was
 * frozen on the props of the first render — and an Inertia visit reuses the
 * component instance, so setup never runs again. Add enough water to pass that
 * first maximum and today's bar was clipped at the top of the chart while the
 * ring above it announced the real total.
 */
const axeY = computed(() => ({
    max: Math.max(props.goal, ...props.data.map((item) => item.total)) + 200, // Add some padding above goal
    ticks: { stepSize: 500 },
}))

const infobulle = {
    accent: 'accent-info',
    callbacks: {
        label: (context) => `${context.raw} ml`,
        title: (context) => context[0].label,
    },
}
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-[300px] pt-4"
        :infobulle="infobulle"
        :axe-x="{ ticks: { textTransform: 'uppercase' } }"
        :axe-y="axeY"
    />
</template>
