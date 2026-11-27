<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'
import { grille } from './chartConfig'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Filter out parts with no difference
const differences = computed(() => props.data.filter((item) => item.diff !== 0))

// Le nom français de la partie (« Taille »), pas sa clef (`Waist`).
const labels = computed(() => differences.value.map((item) => item.label ?? item.part))

const datasets = computed(() => [
    {
        label: 'Différence',
        data: differences.value.map((item) => item.diff),
        backgroundColor: (context) => (context.raw > 0 ? jeton('trend-up') : jeton('trend-down')),
        borderRadius: 6,
        borderSkipped: false,
    },
])

const infobulle = {
    accent: 'shadow-cast',
    opaque: true,
    callbacks: {
        label: (context) => {
            const value = context.parsed.x
            const sign = value > 0 ? '+' : ''
            // L'étiquette est le nom affiché (« Taille »), pas la clef (`Waist`).
            const partData = props.data.find((d) => (d.label ?? d.part) === context.label)
            const unit = partData ? partData.unit : ''
            return `${sign}${value} ${unit}`
        },
    },
}

const axeX = { grid: { display: true, ...grille() } }
</script>

<template>
    <BaseChart
        description="Variation récente de chaque mensuration"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-64"
        index-axis="y"
        :infobulle="infobulle"
        :axe-x="axeX"
        :axe-y="{ grid: { display: false } }"
        :vide="labels.length === 0"
    >
        <template #vide>
            <GlassEmptyState taille="ligne" icon="straighten" title="Aucun changement enregistré" />
        </template>
    </BaseChart>
</template>
