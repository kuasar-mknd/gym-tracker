import { describe, it, expect, vi } from 'vitest'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const OneRepMaxChart = (await import('@/Components/Stats/OneRepMaxChart.vue')).default

const chartOf = (data) => mount(OneRepMaxChart, { props: { data } }).findComponent({ name: 'Line' })

const history = [
    { date: '01/07', one_rep_max: 95 },
    { date: '08/07', one_rep_max: 100.5 },
    { date: '15/07', one_rep_max: 102 },
]

describe('OneRepMaxChart', () => {
    it('aligne chaque 1RM sur sa date, dans l’ordre reçu', () => {
        const chart = chartOf(history)

        expect(chart.props('data').labels).toEqual(['01/07', '08/07', '15/07'])
        expect(chart.props('data').datasets[0].data).toEqual([95, 100.5, 102])
    })

    it('nomme la série pour l’estimation qu’elle porte', () => {
        expect(chartOf(history).props('data').datasets[0].label).toBe('Estimé 1RM (kg)')
    })

    it('trace un historique vide sans point', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets).toHaveLength(1)
        expect(data.datasets[0].data).toEqual([])
    })

    it('trace une seule estimation sans la perdre', () => {
        expect(chartOf([history[0]]).props('data').datasets[0].data).toEqual([95])
    })

    it('suit les données quand l’exercice change', async () => {
        const wrapper = mount(OneRepMaxChart, { props: { data: history } })

        await wrapper.setProps({ data: [{ date: '20/07', one_rep_max: 60 }] })

        const chart = wrapper.findComponent({ name: 'Line' })

        expect(chart.props('data').labels).toEqual(['20/07'])
        expect(chart.props('data').datasets[0].data).toEqual([60])
    })

    /**
     * A 1RM history spans a handful of kilos. Anchoring the axis at zero would
     * flatten every progression into a horizontal line, so the scale is left to
     * fit the data — and the dates must stay readable underneath it.
     */
    it('laisse l’axe cadrer sur les valeurs et garde les dates lisibles', () => {
        const scales = chartOf(history).props('options').scales

        expect(scales.y.beginAtZero).toBeUndefined()
        expect(scales.x.display).not.toBe(false)
        expect(scales.x.ticks.color).toBe(jeton('text-muted'))
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

            dataset().backgroundColor({ chart: { ctx, chartArea: { top: 0, bottom: 192 } } })

            expect(stops).toEqual([
                [0, 0, 0, 192],
                [0, jetonTransparent('accent-secondary', 0.2)],
                [1, jetonTransparent('accent-secondary', 0)],
            ])
        })

        it('ne peint rien tant que la zone de tracé n’est pas mesurée', () => {
            expect(dataset().backgroundColor({ chart: { ctx: {}, chartArea: undefined } })).toBeNull()
        })
    })
})
