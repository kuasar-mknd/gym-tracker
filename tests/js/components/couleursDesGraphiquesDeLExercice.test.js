import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { chartDataOf, chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const MaxRepsChart = (await import('@/Components/Stats/MaxRepsChart.vue')).default
const MonthlyVolumeChart = (await import('@/Components/Stats/MonthlyVolumeChart.vue')).default
const OneRepMaxPercentagesChart = (await import('@/Components/Stats/OneRepMaxPercentagesChart.vue')).default
const SetsPerSessionChart = (await import('@/Components/Stats/SetsPerSessionChart.vue')).default
const MaxWeightChart = (await import('@/Components/Stats/MaxWeightChart.vue')).default
const VolumeTrendChart = (await import('@/Components/Stats/VolumeTrendChart.vue')).default
const WeightRepsScatterChart = (await import('@/Components/Stats/WeightRepsScatterChart.vue')).default

/**
 * Les quatre cartes de la fiche d'un exercice : une couleur chacune. Les
 * jetons attendus sont écrits ici, pas relus sur les composants.
 */
const cartes = [
    { nom: 'Volume', composant: VolumeTrendChart, type: 'Bar', cle: 'backgroundColor', jeton: 'accent-primary' },
    { nom: 'Endurance', composant: MaxRepsChart, type: 'Line', cle: 'borderColor', jeton: 'accent-tertiary' },
    { nom: 'Charge max', composant: MaxWeightChart, type: 'Line', cle: 'borderColor', jeton: 'accent-state' },
    {
        nom: 'Poids vs reps',
        composant: WeightRepsScatterChart,
        type: 'Scatter',
        cle: 'borderColor',
        jeton: 'accent-secondary',
    },
]

const couleurDe = ({ composant, type, cle }) =>
    chartDataOf(mount(composant, { props: { data: [] } }), type).datasets[0][cle]

describe('Couleurs des graphiques de la fiche d’un exercice', () => {
    it.each(cartes)('peint la carte $nom avec $jeton', (carte) => {
        expect(couleurDe(carte)).toBe(jeton(carte.jeton))
    })

    it('donne quatre couleurs distinctes aux quatre cartes', () => {
        const couleurs = cartes.map(couleurDe)

        expect(couleurs.every((couleur) => couleur !== '')).toBe(true)
        expect(new Set(couleurs).size).toBe(4)
    })

    it('garde le point de survol de l’endurance dans sa couleur', () => {
        const serie = chartDataOf(mount(MaxRepsChart, { props: { data: [] } }), 'Line').datasets[0]

        expect(serie.pointBorderColor).toBe(jeton('accent-tertiary'))
        expect(serie.pointHoverBackgroundColor).toBe(jeton('accent-tertiary'))
    })

    it('accorde le trait de l’infobulle d’Endurance à sa courbe', () => {
        const options = chartOptionsOf(mount(MaxRepsChart, { props: { data: [] } }), 'Line')

        expect(options.plugins.tooltip.borderColor).toBe(jetonTransparent('accent-tertiary', 0.2))
    })

    it.each([
        ['VolumeTrendChart', VolumeTrendChart],
        ['MonthlyVolumeChart', MonthlyVolumeChart],
        ['OneRepMaxPercentagesChart', OneRepMaxPercentagesChart],
        ['SetsPerSessionChart', SetsPerSessionChart],
    ])('%s ne porte pas de survol violet', (_nom, composant) => {
        const serie = chartDataOf(mount(composant, { props: { data: [] } }), 'Bar').datasets[0]

        expect(serie.hoverBackgroundColor).not.toBe(jeton('accent-tertiary'))
        expect(serie.hoverBackgroundColor).toBeUndefined()
    })

    it('remplit le nuage de points en magenta à 60 %', () => {
        const serie = chartDataOf(mount(WeightRepsScatterChart, { props: { data: [] } }), 'Scatter').datasets[0]

        expect(serie.backgroundColor).toBe(jetonTransparent('accent-secondary', 0.6))
    })
})
