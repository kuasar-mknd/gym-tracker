<script setup>
/**
 * Le graphique de base : un seul enregistrement Chart.js, un seul habillage
 * (infobulle, légende, axes), une seule hauteur à déclarer. Chaque carte ne
 * garde que ses séries et ce qui la distingue.
 */
import * as VueChartJs from 'vue-chartjs'
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
} from 'chart.js'
import { computed } from 'vue'
import { jeton } from '@/Utils/couleurs'
import { optionsDAnneau } from '@/Utils/donut'
import { fusionner, graduations, grille, infobulle as habillageInfobulle } from './chartConfig'

ChartJS.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Title,
    Tooltip,
)

const COMPOSANTS = { bar: 'Bar', line: 'Line', doughnut: 'Doughnut', scatter: 'Scatter' }

const props = defineProps({
    type: {
        type: String,
        default: 'bar',
        validator: (valeur) => ['bar', 'line', 'doughnut', 'scatter'].includes(valeur),
    },
    labels: { type: Array, default: () => [] },
    datasets: { type: Array, required: true },
    hauteur: { type: String, default: 'h-48' },
    legende: { type: [Boolean, Object], default: null },
    infobulle: { type: Object, default: () => ({}) },
    axeX: { type: [Boolean, Object], default: true },
    axeY: { type: [Boolean, Object], default: true },
    axeY1: { type: Object, default: null },
    indexAxis: { type: String, default: 'x' },
    interaction: { type: Object, default: null },
    lueur: { type: String, default: '' },
    lueurOpacite: { type: Number, default: 0.2 },
    options: { type: Object, default: () => ({}) },
    plugins: { type: Array, default: () => [] },
    vide: { type: Boolean, default: false },
})

// Résolu à l'usage, jamais au chargement : les suites de test remplacent
// vue-chartjs par un module qui n'exporte que le tracé qu'elles regardent.
const composant = computed(() => VueChartJs[COMPOSANTS[props.type]])

const chartData = computed(() => ({ labels: props.labels, datasets: props.datasets }))

const estUnObjet = (valeur) => valeur !== null && typeof valeur === 'object'

// Absente, la légende suit le tracé : cachée sur une courbe ou des barres,
// visible sous un anneau.
const legende = () => {
    if (!props.legende) {
        return { display: false }
    }
    const visible = {
        display: true,
        position: 'bottom',
        labels: {
            color: jeton('text-muted'),
            font: { size: 10, weight: 'bold' },
            padding: 12,
            boxHeight: 8,
            usePointStyle: true,
            pointStyle: 'circle',
        },
    }
    return estUnObjet(props.legende) ? fusionner(visible, props.legende) : visible
}

// Un axe caché garde son échelle : des barres partent toujours de zéro,
// qu'on lise leurs graduations ou non.
const axe = (reglage, defaut) => {
    if (reglage === false) {
        return { ...defaut, display: false }
    }
    return reglage === true ? defaut : fusionner(defaut, reglage)
}

const axeX = () =>
    axe(props.axeX, { display: true, grid: { display: false }, ticks: graduations(), border: { display: false } })

const axeY = (reglage) =>
    axe(reglage, {
        display: true,
        ...(props.type === 'bar' ? { beginAtZero: true } : {}),
        grid: grille(),
        ticks: graduations(),
        border: { display: false },
    })

const chartOptions = computed(() => {
    const { accent, opaque, ...reglages } = props.infobulle

    if (props.type === 'doughnut') {
        const anneau = optionsDAnneau(reglages.callbacks?.label)
        const specifiques = { plugins: { tooltip: reglages } }
        if (props.legende === false) {
            specifiques.plugins.legend = { display: false }
        } else if (estUnObjet(props.legende)) {
            specifiques.plugins.legend = props.legende
        }
        return fusionner(fusionner(anneau, specifiques), props.options)
    }

    const base = {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: props.indexAxis,
        plugins: {
            legend: legende(),
            tooltip: { ...habillageInfobulle({ accent, opaque }), displayColors: false, ...reglages },
        },
        scales: { x: axeX(), y: axeY(props.axeY) },
    }
    if (props.interaction) {
        base.interaction = props.interaction
    }
    if (props.axeY1) {
        base.scales.y1 = axeY(props.axeY1)
    }
    return fusionner(base, props.options)
})

const style = computed(() =>
    props.lueur
        ? { '--lueur': `var(--color-${props.lueur})`, '--lueur-opacite': String(props.lueurOpacite) }
        : undefined,
)
</script>

<template>
    <div :class="['relative w-full', hauteur, { 'avec-lueur': lueur }]" :style="style">
        <slot v-if="vide" name="vide" />
        <template v-else>
            <component :is="composant" :data="chartData" :options="chartOptions" :plugins="plugins" />
            <slot name="surcouche" />
        </template>
    </div>
</template>

<style scoped>
.avec-lueur :deep(canvas) {
    filter: drop-shadow(0 4px 6px rgb(from var(--lueur) r g b / var(--lueur-opacite)));
}
</style>
