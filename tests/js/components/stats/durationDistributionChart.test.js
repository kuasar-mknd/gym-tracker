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

const DurationDistributionChart = (await import('@/Components/Stats/DurationDistributionChart.vue')).default

const chartOf = (data) => mount(DurationDistributionChart, { props: { data } }).findComponent({ name: 'Doughnut' })

/** What Chart.js hands a doughnut tooltip: the arc, plus the ring's own total. */
const arc = (label, value, total) => ({
    label,
    raw: value,
    datasetIndex: 0,
    chart: { _metasets: [{ total }] },
})

const buckets = [
    { label: '< 30 min', count: 3 },
    { label: '30-60 min', count: 10 },
    { label: '60-90 min', count: 6 },
    { label: '90+ min', count: 1 },
]

describe('DurationDistributionChart', () => {
    it('découpe l’anneau par tranche de durée, dans l’ordre reçu', () => {
        const chart = chartOf(buckets)

        expect(chart.props('data').labels).toEqual(['< 30 min', '30-60 min', '60-90 min', '90+ min'])
        expect(chart.props('data').datasets[0].data).toEqual([3, 10, 6, 1])
    })

    /**
     * The colour is looked up by label, not by position: the API omits a bucket
     * nobody ever hit, and indexing the palette would then paint "90+ min" with
     * the colour the legend gives to the shortest sessions.
     */
    it('donne à chaque tranche sa couleur, même quand une tranche manque', () => {
        const chart = chartOf([
            { label: '< 30 min', count: 3 },
            { label: '90+ min', count: 1 },
        ])

        expect(chart.props('data').datasets[0].backgroundColor).toEqual(['#00E5FF', '#FF5500'])
    })

    it('retombe sur le gris pour une tranche inconnue', () => {
        const chart = chartOf([{ label: '120+ min', count: 2 }])

        expect(chart.props('data').datasets[0].backgroundColor).toEqual(['#64748B'])
    })

    it('ne découpe rien sans séance', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
        expect(data.datasets[0].backgroundColor).toEqual([])
    })

    describe('survol', () => {
        const tooltip = () => chartOf(buckets).props('options').plugins.tooltip.callbacks.label

        it('annonce la part de la tranche dans le total', () => {
            expect(tooltip()(arc('30-60 min', 10, 20))).toBe('30-60 min: 10 (50%)')
        })

        it('arrondit le pourcentage au point le plus proche', () => {
            // 1 sur 3 se lit 33 %, pas 33.33333333333333 %.
            expect(tooltip()(arc('< 30 min', 1, 3))).toBe('< 30 min: 1 (33%)')
            expect(tooltip()(arc('< 30 min', 2, 3))).toBe('< 30 min: 2 (67%)')
        })

        it('annonce la totalité pour une tranche seule', () => {
            expect(tooltip()(arc('90+ min', 4, 4))).toBe('90+ min: 4 (100%)')
        })

        it('lit une tranche vide comme zéro, sans « undefined »', () => {
            // Chart.js ne passe ni libellé ni valeur pour un arc de taille
            // nulle : l'infobulle doit rester lisible plutôt qu'afficher
            // « undefined: undefined (NaN%) ».
            expect(tooltip()(arc(undefined, undefined, 4))).toBe(': 0 (0%)')
        })
    })
})
