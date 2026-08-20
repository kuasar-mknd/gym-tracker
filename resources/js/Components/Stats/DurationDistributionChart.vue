<script setup>
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import { computed, ref } from 'vue'
import { optionsDAnneau, pluginCentreDAnneau } from '@/Utils/donut'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

// Colors for the buckets
const bucketColors = {
    '< 30 min': '#00E5FF', // cyan-pure
    '30-60 min': '#FF0080', // hot-pink
    '60-90 min': '#8800FF', // vivid-violet
    '90+ min': '#FF5500', // electric-orange
}

const chartData = computed(() => {
    // Expecting data to be [{ label: '< 30 min', count: 5 }, ...]
    const labels = props.data.map((item) => item.label)
    const counts = props.data.map((item) => item.count)
    const backgroundColor = labels.map((label) => bucketColors[label] || '#64748B')

    return {
        labels: labels,
        datasets: [
            {
                data: counts,
                backgroundColor: backgroundColor,
                borderWidth: 0,
                hoverOffset: 10,
                borderRadius: 4,
            },
        ],
    }
})

/** Le centre réel de l'anneau, rapporté par Chart.js après chaque tracé. */
const centre = ref(null)

const chartOptions = optionsDAnneau((context) => {
    const label = context.label || ''
    const value = context.raw || 0
    const total = context.chart._metasets[context.datasetIndex].total
    const percentage = Math.round((value / total) * 100) + '%'

    return `${label}: ${value} (${percentage})`
})

const plugins = [pluginCentreDAnneau((position) => (centre.value = position))]
</script>

<template>
    <div class="relative h-48 w-full">
        <Doughnut :data="chartData" :options="chartOptions" :plugins="plugins" />
        <!--
            Une icône centrale ici aussi : les deux anneaux sont empilés dans la
            même colonne, et l'un l'avait quand l'autre non (#1316). Même
            mécanisme, donc même justesse — la position vient du centre que
            Chart.js rapporte, avec un repli au centre du conteneur tant qu'il
            n'a pas tracé.
        -->
        <div
            class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
            :style="centre ? { left: `${centre.x}px`, top: `${centre.y}px` } : { left: '50%', top: '50%' }"
        >
            <span class="material-symbols-outlined text-text-muted/20 text-4xl" aria-hidden="true">timer</span>
        </div>
    </div>
</template>
