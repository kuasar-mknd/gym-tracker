import { describe, it, expect, vi } from 'vitest'
import { jeton } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const WeightDistributionChart = (await import('@/Components/Stats/WeightDistributionChart.vue')).default

const chartOf = (data) => mount(WeightDistributionChart, { props: { data } }).findComponent({ name: 'Bar' })

const bins = [
    { label: '40-60', count: 12 },
    { label: '60-80', count: 5 },
    { label: '80-100', count: 0 },
]

describe('WeightDistributionChart', () => {
    it('compte les séries par tranche de charge, dans l’ordre des tranches', () => {
        const chart = chartOf(bins)

        expect(chart.props('data').labels).toEqual(['40-60', '60-80', '80-100'])
        expect(chart.props('data').datasets[0].data).toEqual([12, 5, 0])
    })

    it('ne dessine aucune barre sans série enregistrée', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
    })

    it('gradue l’axe par série entière', () => {
        // Un compte de séries est un entier ; sans pas imposé, Chart.js gradue
        // « 0, 0.5, 1 » dès qu'une seule tranche est occupée.
        const ticks = chartOf(bins).props('options').scales.y.ticks

        expect(ticks.stepSize).toBe(1)
        expect(chartOf(bins).props('options').scales.y.beginAtZero).toBe(true)
    })

    it('affiche la tranche sous la barre, pas son rang', () => {
        const scale = { getLabelForValue: (index) => ['40-60', '60-80', '80-100'][index] }
        const callback = chartOf(bins).props('options').scales.x.ticks.callback

        // Retourner `val` tel quel afficherait 0, 1, 2 : Chart.js passe l'index
        // de la graduation, pas son libellé.
        expect(callback.call(scale, 1)).toBe('60-80')
    })

    describe('survol', () => {
        const tooltip = () => chartOf(bins).props('options').plugins.tooltip

        it('compte les séries de la tranche', () => {
            expect(tooltip().callbacks.label({ raw: 12 })).toBe('12 séries')
        })

        it('titre la tranche avec son unité', () => {
            expect(tooltip().callbacks.title([{ label: '40-60' }])).toBe('40-60 kg')
        })
    })

    describe('dégradé des barres', () => {
        const dataset = () => chartOf(bins).props('data').datasets[0]

        it('descend du bleu au violet, du haut de la barre vers l’axe', () => {
            const stops = []
            const ctx = {
                createLinearGradient: (...coordinates) => {
                    stops.push(coordinates)

                    return { addColorStop: (offset, color) => stops.push([offset, color]) }
                },
            }

            dataset().backgroundColor({ chart: { ctx, chartArea: { top: 0, bottom: 150 } } })

            expect(stops).toEqual([
                [0, 0, 0, 150],
                [0, jeton('accent-info')],
                [1, jeton('accent-tertiary')],
            ])
        })

        it('ne peint rien tant que la zone de tracé n’est pas mesurée', () => {
            const painted = dataset().backgroundColor({ chart: { ctx: {}, chartArea: undefined } })

            expect(painted).toBeNull()
        })
    })
})
