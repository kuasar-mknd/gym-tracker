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

const labels = computed(() => props.data.map((item) => item.label))

const datasets = computed(() => [
    {
        data: props.data.map((item) => item.count),
        /*
         * Quatre moments, quatre couleurs DISTINCTES.
         *
         * « Soir » et « Nuit » portaient un violet et un indigo, deux
         * teintes proches mais séparables ; la conversion les a envoyés
         * sur le même `accent-tertiary`, et deux parts de l'anneau sont
         * devenues impossibles à distinguer — quatre étiquettes pour
         * trois couleurs.
         *
         * Ces quatre-là ne portent aucune intention : elles doivent
         * seulement se séparer. Le bleu de `category-core` fait la nuit,
         * puisqu'il est la teinte la plus éloignée des trois autres.
         */
        backgroundColor: [
            jeton('accent-info'), // Matin
            jeton('accent-warning'), // Après-midi
            jeton('accent-tertiary'), // Soir
            jeton('category-core'), // Nuit
        ],
        hoverBackgroundColor: [
            jeton('accent-info'),
            jeton('accent-primary'),
            jeton('accent-tertiary'),
            jeton('category-core'),
        ],
        borderWidth: 2,
        borderColor: jeton('surface-card'),
        hoverOffset: 4,
    },
])

const infobulle = {
    callbacks: {
        label: (context) => {
            const value = context.raw
            const total = context.chart._metasets[context.datasetIndex].total
            const percentage = Math.round((value / total) * 100)

            return ` ${value} séances (${percentage}%)`
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
            Positionnée sur le centre que Chart.js rapporte, et non sur celui du
            conteneur. Un `-ml-[120px]` codé en dur compensait la légende de
            droite : mesuré, il posait l'icône à 41 px du vrai centre, et il
            aurait fallu le refaire à chaque étiquette plus longue (#1316).

            Le repli sur 50 %/50 % n'est pas décoratif : sans lui, l'icône
            n'existerait pas tant que le greffon n'a pas tracé une première
            fois — et pas du tout si le graphique ne trace jamais.
        -->
        <template #surcouche>
            <div
                class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
                :style="centre ? { left: `${centre.x}px`, top: `${centre.y}px` } : { left: '50%', top: '50%' }"
            >
                <span class="material-symbols-outlined text-text-muted/20 text-4xl" aria-hidden="true">schedule</span>
            </div>
        </template>
    </BaseChart>
</template>
