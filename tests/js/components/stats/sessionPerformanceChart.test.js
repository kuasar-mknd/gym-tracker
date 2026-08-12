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

const SessionPerformanceChart = (await import('@/Components/Stats/SessionPerformanceChart.vue')).default

const chartOf = (data) => mount(SessionPerformanceChart, { props: { data } }).findComponent({ name: 'Bar' })

/** Datasets are found by label: their order in the array is a drawing concern. */
const seriesOf = (chart, label) => chart.props('data').datasets.find((dataset) => dataset.label === label).data

const session = (attributes) => ({ formatted_date: '01/07/2026', sets: [], best_1rm: 0, ...attributes })

describe('SessionPerformanceChart', () => {
    /** The history arrives most-recent first; a progress chart must read left to right. */
    it('remet les séances dans l’ordre chronologique', () => {
        const chart = chartOf([
            session({ formatted_date: '15/07/2026', best_1rm: 105 }),
            session({ formatted_date: '08/07/2026', best_1rm: 100 }),
            session({ formatted_date: '01/07/2026', best_1rm: 95 }),
        ])

        expect(chart.props('data').labels).toEqual(['01/07', '08/07', '15/07'])
        expect(seriesOf(chart, 'Meilleur 1RM (kg)')).toEqual([95, 100, 105])
    })

    /**
     * `props.data.reverse()` reverses in place. The page hands the same array to
     * the table below the chart, which would then have listed the oldest
     * session first — a defect visible nowhere near this component.
     */
    it('ne retourne pas le tableau que la page lui prête', () => {
        const data = [
            session({ formatted_date: '15/07/2026' }),
            session({ formatted_date: '08/07/2026' }),
            session({ formatted_date: '01/07/2026' }),
        ]

        chartOf(data)

        expect(data.map((entry) => entry.formatted_date)).toEqual(['15/07/2026', '08/07/2026', '01/07/2026'])
    })

    it('abrège la date au jour et au mois', () => {
        expect(chartOf([session({ formatted_date: '05/12/2026' })]).props('data').labels).toEqual(['05/12'])
    })

    it('additionne le volume de chaque série de la séance', () => {
        const chart = chartOf([
            session({
                sets: [
                    { weight: 100, reps: 5 },
                    { weight: 80, reps: 10 },
                ],
            }),
        ])

        expect(seriesOf(chart, 'Volume Total (kg)')).toEqual([1300])
    })

    it('compte pour zéro une série au poids ou aux répétitions manquants', () => {
        // Le poids du corps est enregistré sans charge : la série ne doit rien
        // ajouter au volume, et surtout pas propager un NaN sur toute la barre.
        const chart = chartOf([
            session({
                sets: [
                    { weight: null, reps: 12 },
                    { weight: 60, reps: undefined },
                    { weight: 60, reps: 10 },
                ],
            }),
        ])

        expect(seriesOf(chart, 'Volume Total (kg)')).toEqual([600])
    })

    it('trace zéro quand la séance n’a pas de 1RM estimé', () => {
        const chart = chartOf([session({ best_1rm: null })])

        expect(seriesOf(chart, 'Meilleur 1RM (kg)')).toEqual([0])
    })

    it('ne trace aucune séance sans historique', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets.map((dataset) => dataset.data)).toEqual([[], []])
    })

    it('sépare les deux échelles : volume à gauche, 1RM à droite', () => {
        const chart = chartOf([session({})])
        const datasetOf = (label) => chart.props('data').datasets.find((dataset) => dataset.label === label)

        // Les deux séries partagent un graphe mais pas une unité : les
        // confondre écraserait la courbe de 1RM au ras de l'axe.
        expect(datasetOf('Volume Total (kg)').yAxisID).toBe('y')
        expect(datasetOf('Volume Total (kg)').type).toBe('bar')
        expect(datasetOf('Meilleur 1RM (kg)').yAxisID).toBe('y1')
        expect(datasetOf('Meilleur 1RM (kg)').type).toBe('line')
        expect(chart.props('options').scales.y.position).toBe('left')
        expect(chart.props('options').scales.y1.position).toBe('right')
    })

    describe('axe des volumes', () => {
        const tick = (value) =>
            chartOf([session({})])
                .props('options')
                .scales.y.ticks.callback(value)

        it('abrège les milliers sans les arrondir au millier', () => {
            expect(tick(1500)).toBe('1.5k')
            expect(tick(12750)).toBe('12.8k')
        })

        it('marque le premier millier', () => {
            expect(tick(1000)).toBe('1.0k')
        })

        it('laisse les volumes plus faibles tels quels', () => {
            expect(tick(999)).toBe(999)
            expect(tick(0)).toBe(0)
        })
    })

    describe('survol', () => {
        const tooltip = () => chartOf([session({})]).props('options').plugins.tooltip.callbacks.label

        it('préfixe la valeur du nom de la série', () => {
            const label = tooltip()({ dataset: { label: 'Volume Total (kg)' }, parsed: { y: 12000 } })

            expect(label).toBe(`Volume Total (kg): ${(12000).toLocaleString()}`)
        })

        it('n’ajoute pas de séparateur quand la série n’a pas de nom', () => {
            expect(tooltip()({ dataset: {}, parsed: { y: 42 } })).toBe('42')
        })

        it('n’annonce rien pour un point absent', () => {
            expect(tooltip()({ dataset: { label: 'Volume Total (kg)' }, parsed: { y: null } })).toBe(
                'Volume Total (kg): ',
            )
        })
    })
})
