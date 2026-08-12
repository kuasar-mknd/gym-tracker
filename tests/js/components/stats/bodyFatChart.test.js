import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const BodyFatChart = (await import('@/Components/Stats/BodyFatChart.vue')).default

const chartOf = (data) => mount(BodyFatChart, { props: { data } }).findComponent({ name: 'Line' })

const history = [
    { date: '01 juil', body_fat: 18.4 },
    { date: '15 juil', body_fat: 17.9 },
    { date: '31 juil', body_fat: 17.2 },
]

describe('BodyFatChart', () => {
    it('aligne chaque taux sur sa date, dans l’ordre reçu', () => {
        const chart = chartOf(history)

        expect(chart.props('data').labels).toEqual(['01 juil', '15 juil', '31 juil'])
        expect(chart.props('data').datasets[0].data).toEqual([18.4, 17.9, 17.2])
    })

    it('nomme la série pour ce qu’elle mesure', () => {
        expect(chartOf(history).props('data').datasets[0].label).toBe('Masse Grasse (%)')
    })

    it('trace un historique vide sans point', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
    })

    it('trace une seule mesure sans la perdre', () => {
        expect(chartOf([history[0]]).props('data').datasets[0].data).toEqual([18.4])
    })

    it('libelle le survol en pourcentage', () => {
        const label = chartOf(history)
            .props('options')
            .plugins.tooltip.callbacks.label({ parsed: { y: 17.2 } })

        expect(label).toBe('17.2 %')
    })

    /**
     * A body fat percentage moves by tenths of a point over months. Forcing the
     * axis to zero would flatten the whole history into a straight line, so the
     * scale is deliberately left to fit the data.
     */
    it('laisse l’axe cadrer sur les valeurs plutôt que sur zéro', () => {
        expect(chartOf(history).props('options').scales.y.beginAtZero).toBeUndefined()
        expect(chartOf(history).props('options').scales.y.display).toBe(true)
    })

    describe('dégradé de remplissage', () => {
        const dataset = () => chartOf(history).props('data').datasets[0]

        it('s’efface de la courbe vers l’axe', () => {
            const stops = []
            const ctx = {
                createLinearGradient: (...coordinates) => {
                    stops.push(coordinates)

                    return { addColorStop: (offset, color) => stops.push([offset, color]) }
                },
            }

            dataset().backgroundColor({ chart: { ctx, chartArea: { top: 0, bottom: 128 } } })

            expect(stops).toEqual([
                [0, 0, 0, 128],
                [0, 'rgba(255, 0, 255, 0.2)'],
                [1, 'rgba(255, 0, 255, 0)'],
            ])
        })

        it('ne peint rien tant que la zone de tracé n’est pas mesurée', () => {
            expect(dataset().backgroundColor({ chart: { ctx: {}, chartArea: undefined } })).toBeNull()
        })
    })
})
