<script setup>
import { Doughnut } from 'vue-chartjs'
import { jeton } from '@/Utils/couleurs'
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

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.label),
        datasets: [
            {
                data: props.data.map((item) => item.count),
                backgroundColor: [
                    jeton('accent-info'), // Sky 400 (Matin)
                    jeton('accent-warning'), // Amber 500 (Après-midi)
                    jeton('accent-tertiary'), // Violet 500 (Soir)
                    jeton('accent-tertiary'), // Indigo 900 (Nuit)
                ],
                hoverBackgroundColor: [
                    jeton('accent-info'), // Sky 500
                    jeton('accent-primary'), // Amber 600
                    jeton('accent-tertiary'), // Violet 600
                    jeton('accent-tertiary'), // Indigo 950
                ],
                borderWidth: 2,
                borderColor: jeton('surface-card'),
                hoverOffset: 4,
            },
        ],
    }
})

/** Le centre réel de l'anneau, rapporté par Chart.js après chaque tracé. */
const centre = ref(null)

const chartOptions = optionsDAnneau((context) => {
    const value = context.raw
    const total = context.chart._metasets[context.datasetIndex].total
    const percentage = Math.round((value / total) * 100)

    return ` ${value} séances (${percentage}%)`
})

const plugins = [pluginCentreDAnneau((position) => (centre.value = position))]
</script>

<template>
    <div class="relative h-48 w-full">
        <Doughnut :data="chartData" :options="chartOptions" :plugins="plugins" />
        <!--
            Positionnée sur le centre que Chart.js rapporte, et non sur celui du
            conteneur. Un `-ml-[120px]` codé en dur compensait la légende de
            droite : mesuré, il posait l'icône à 41 px du vrai centre, et il
            aurait fallu le refaire à chaque étiquette plus longue (#1316).

            Le repli sur 50 %/50 % n'est pas décoratif : sans lui, l'icône
            n'existerait pas tant que le greffon n'a pas tracé une première
            fois — et pas du tout si le graphique ne trace jamais.
        -->
        <div
            class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
            :style="centre ? { left: `${centre.x}px`, top: `${centre.y}px` } : { left: '50%', top: '50%' }"
        >
            <span class="material-symbols-outlined text-text-muted/20 text-4xl" aria-hidden="true">schedule</span>
        </div>
    </div>
</template>
