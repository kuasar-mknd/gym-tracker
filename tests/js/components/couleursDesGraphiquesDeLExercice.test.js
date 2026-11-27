import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { jeton } from '@/Utils/couleurs'
import { chartDataOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const MaxRepsChart = (await import('@/Components/Stats/MaxRepsChart.vue')).default
const MaxWeightChart = (await import('@/Components/Stats/MaxWeightChart.vue')).default
const VolumeTrendChart = (await import('@/Components/Stats/VolumeTrendChart.vue')).default
const WeightRepsScatterChart = (await import('@/Components/Stats/WeightRepsScatterChart.vue')).default

/**
 * Les quatre cartes de la fiche d'un exercice : une couleur chacune. Les
 * jetons attendus sont écrits ici, pas relus sur les composants.
 */
const cartes = [
    { nom: 'Volume', composant: VolumeTrendChart, type: 'Bar', cle: 'backgroundColor', jeton: 'accent-primary' },
    { nom: 'Endurance', composant: MaxRepsChart, type: 'Line', cle: 'borderColor', jeton: 'accent-secondary' },
    { nom: 'Charge max', composant: MaxWeightChart, type: 'Line', cle: 'borderColor', jeton: 'accent-state' },
    {
        nom: 'Poids vs reps',
        composant: WeightRepsScatterChart,
        type: 'Scatter',
        cle: 'borderColor',
        jeton: 'accent-tertiary',
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

        expect(serie.pointBorderColor).toBe(jeton('accent-secondary'))
        expect(serie.pointHoverBackgroundColor).toBe(jeton('accent-secondary'))
    })
})
