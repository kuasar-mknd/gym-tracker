<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { grille } from './chartConfig'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Filter out parts with no difference
const differences = computed(() => props.data.filter((item) => item.diff !== 0))

const labels = computed(() => differences.value.map((item) => item.part))

const datasets = computed(() => [
    {
        label: 'Différence',
        data: differences.value.map((item) => item.diff),
        backgroundColor: (context) => {
            const value = context.raw
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null

            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            if (value > 0) {
                // Green/Cyan for growth
                gradient.addColorStop(0, jeton('trend-up'))
                gradient.addColorStop(1, jetonTransparent('trend-up', 0.55))
            } else {
                // Red/Orange for decrease
                gradient.addColorStop(0, jeton('trend-down'))
                gradient.addColorStop(1, jetonTransparent('trend-down', 0.55))
            }
            return gradient
        },
        borderRadius: 6,
        borderSkipped: false,
    },
])

const infobulle = {
    opaque: true,
    callbacks: {
        label: (context) => {
            const value = context.parsed.x
            const sign = value > 0 ? '+' : ''
            // Find the unit for this part
            const partData = props.data.find((d) => d.part === context.label)
            const unit = partData ? partData.unit : ''
            return `${sign}${value} ${unit}`
        },
    },
}

const axeX = { grid: { display: true, ...grille() } }
</script>

<template>
    <BaseChart
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        index-axis="y"
        lueur="shadow-cast"
        :infobulle="infobulle"
        :axe-x="axeX"
        :axe-y="{ grid: { display: false } }"
        :vide="labels.length === 0"
    >
        <template #vide>
            <div class="text-text-muted flex h-full items-center justify-center font-medium">
                Aucun changement enregistré
            </div>
        </template>
    </BaseChart>
</template>
