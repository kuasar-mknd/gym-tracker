<script setup>
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { computed, ref } from 'vue'
import { parseCalendarDate } from '@/Utils/date'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

/**
 * Les sept métriques du journal, dans les teintes de la palette.
 *
 * La palette et non les accents, pour deux raisons que ce composant a apprises
 * à ses dépens. D'abord ces sept-là ne portent aucune intention : « Humeur » et
 * « Stress » ne sont ni une alerte ni une confirmation, ils doivent seulement se
 * distinguer l'un de l'autre. Les avoir pris dans les accents en repliait deux
 * sur la même valeur — « Humeur » et « Motivation » devenaient le même rose, et
 * sept boutons n'offraient plus que six couleurs.
 *
 * Ensuite le bouton actif prend sa couleur en fond et écrit son libellé
 * par-dessus. Chaque teinte de la palette porte du blanc à 4,50:1 au minimum,
 * calculé dans la charte : la lisibilité est donc acquise par construction, et
 * non vérifiée après coup. Le violet des accents rendait 2,97:1 avec de l'encre,
 * ce qu'aucune relecture n'avait vu.
 */
const metrics = [
    { value: 'mood_score', label: 'Humeur', color: jeton('palette-rose'), labelSuffix: '/5' },
    { value: 'sleep_quality', label: 'Sommeil', color: jeton('palette-indigo'), labelSuffix: '/5' },
    { value: 'energy_level', label: 'Énergie', color: jeton('palette-ambre'), labelSuffix: '/10' },
    { value: 'stress_level', label: 'Stress', color: jeton('palette-orange'), labelSuffix: '/10' },
    { value: 'motivation_level', label: 'Motivation', color: jeton('palette-framboise'), labelSuffix: '/10' },
    { value: 'nutrition_score', label: 'Diète', color: jeton('palette-emeraude'), labelSuffix: '/5' },
    { value: 'training_intensity', label: 'Intensité', color: jeton('palette-rouge'), labelSuffix: '/10' },
]

const selectedMetric = ref('mood_score')

const currentMetricConfig = computed(() => {
    return metrics.find((m) => m.value === selectedMetric.value)
})

// Sort data by date ascending
// Y-m-d sorts correctly as a string; there is no reason to build two
// Date objects per comparison, let alone from a calendar day.
const sortedData = computed(() => [...props.data].sort((a, b) => String(a.date).localeCompare(String(b.date))))

const labels = computed(() =>
    sortedData.value.map((item) => {
        const date = parseCalendarDate(item.date)
        return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
    }),
)

const datasets = computed(() => [
    {
        label: currentMetricConfig.value.label,
        data: sortedData.value.map((item) => item[selectedMetric.value]),
        fill: true,
        tension: 0.4,
        borderColor: currentMetricConfig.value.color,
        backgroundColor: (context) => {
            const chart = context.chart
            const { ctx, chartArea } = chart
            if (!chartArea) return null
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
            // Convert hex to rgba for gradient
            const hex = currentMetricConfig.value.color.replace('#', '')
            const r = parseInt(hex.substring(0, 2), 16)
            const g = parseInt(hex.substring(2, 4), 16)
            const b = parseInt(hex.substring(4, 6), 16)

            gradient.addColorStop(0, `rgba(${r}, ${g}, ${b}, 0.2)`)
            gradient.addColorStop(1, `rgba(${r}, ${g}, ${b}, 0)`)
            return gradient
        },
        borderWidth: 3,
        pointRadius: 3,
        pointBackgroundColor: currentMetricConfig.value.color,
        pointBorderColor: jeton('surface-card'),
        pointBorderWidth: 2,
        pointHoverRadius: 6,
        pointHoverBackgroundColor: jeton('surface-card'),
        pointHoverBorderColor: currentMetricConfig.value.color,
        pointHoverBorderWidth: 3,
    },
])

const infobulle = computed(() => ({
    accent: 'shadow-cast',
    callbacks: { label: (context) => `${context.parsed.y} ${currentMetricConfig.value.labelSuffix}` },
}))

const axeY = computed(() => ({
    beginAtZero: true,
    suggestedMax: currentMetricConfig.value.labelSuffix === '/5' ? 5 : 10,
    ticks: { stepSize: 1 },
    grid: { color: jetonTransparent('surface-card', 0.1), borderDash: [5, 5] },
}))
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Metric Selector -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="metric in metrics"
                :key="metric.value"
                @click="selectedMetric = metric.value"
                :aria-pressed="selectedMetric === metric.value"
                :class="[
                    'focus-visible:ring-accent-primary rounded-lg px-3 py-1.5 text-xs font-bold tracking-wider uppercase transition-all focus-visible:ring-2 focus-visible:outline-none',
                    selectedMetric === metric.value
                        ? 'text-text-on-dark-accent scale-105 shadow-lg'
                        : 'text-text-muted hover:text-text-main bg-surface-card/50 hover:bg-surface-card/80',
                ]"
                :style="selectedMetric === metric.value ? { backgroundColor: metric.color } : {}"
            >
                {{ metric.label }}
            </button>
        </div>

        <!-- Chart -->
        <BaseChart
            type="line"
            :labels="labels"
            :datasets="datasets"
            hauteur="h-64"
            lueur="shadow-cast"
            :lueur-opacite="0.1"
            :infobulle="infobulle"
            :axe-x="{ ticks: { maxRotation: 45, minRotation: 0 } }"
            :axe-y="axeY"
            :vide="data.length === 0"
        >
            <template #vide>
                <div class="text-text-muted flex h-full items-center justify-center">
                    Pas assez de données pour afficher le graphique
                </div>
            </template>
        </BaseChart>
    </div>
</template>
