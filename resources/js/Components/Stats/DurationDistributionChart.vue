<script setup>
import { jeton } from '@/Utils/couleurs'
import { computed, ref } from 'vue'
import { pluginCentreDAnneau } from '@/Utils/donut'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Colors for the buckets
const bucketColors = {
    '< 30 min': jeton('accent-info'), // cyan-pure
    '30-60 min': jeton('accent-secondary'), // hot-pink
    '60-90 min': jeton('accent-tertiary'), // vivid-violet
    '90+ min': jeton('accent-primary'), // electric-orange
}

// Expecting data to be [{ label: '< 30 min', count: 5 }, ...]
const labels = computed(() => props.data.map((item) => item.label))

const datasets = computed(() => [
    {
        data: props.data.map((item) => item.count),
        backgroundColor: labels.value.map((label) => bucketColors[label] || jeton('text-muted')),
        borderWidth: 0,
        hoverOffset: 10,
        borderRadius: 4,
    },
])

const infobulle = {
    callbacks: {
        label: (context) => {
            const label = context.label || ''
            const value = context.raw || 0
            const total = context.chart._metasets[context.datasetIndex].total
            const percentage = Math.round((value / total) * 100) + '%'

            return `${label}: ${value} (${percentage})`
        },
    },
}

/** Le centre réel de l'anneau, rapporté par Chart.js après chaque tracé. */
const centre = ref(null)

const plugins = [pluginCentreDAnneau((position) => (centre.value = position))]
</script>

<template>
    <BaseChart
        type="doughnut"
        :labels="labels"
        :datasets="datasets"
        hauteur="h-48"
        :infobulle="infobulle"
        :plugins="plugins"
    >
        <!--
            Une icône centrale ici aussi : les deux anneaux sont empilés dans la
            même colonne, et l'un l'avait quand l'autre non (#1316). Même
            mécanisme, donc même justesse — la position vient du centre que
            Chart.js rapporte, avec un repli au centre du conteneur tant qu'il
            n'a pas tracé.
        -->
        <template #surcouche>
            <div
                class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
                :style="centre ? { left: `${centre.x}px`, top: `${centre.y}px` } : { left: '50%', top: '50%' }"
            >
                <span class="material-symbols-outlined text-text-muted/20 text-4xl" aria-hidden="true">timer</span>
            </div>
        </template>
    </BaseChart>
</template>
