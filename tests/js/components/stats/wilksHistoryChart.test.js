import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the series
 * handed to Chart.js — the scores, their rounding and the dates they sit under.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const WilksHistoryChart = (await import('@/Components/Stats/WilksHistoryChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('options')

const scores = [
    { created_at: '2026-07-04T12:00:00Z', score: '312.456' },
    { created_at: '2026-07-18T12:00:00Z', score: 318.1 },
]

describe('WilksHistoryChart series', () => {
    it('quotes the score to the two decimals a Wilks is quoted with', () => {
        const series = seriesOf(mount(WilksHistoryChart, { props: { data: scores } }))

        expect(series.datasets[0].data).toEqual(['312.46', '318.10'])
    })

    it('reads a score that arrives as a decimal string from the database', () => {
        // The column is a decimal cast, so it lands as a string. Left as one, a
        // rounding call on it would throw rather than plot.
        const series = seriesOf(
            mount(WilksHistoryChart, { props: { data: [{ created_at: scores[0].created_at, score: '99.999' }] } }),
        )

        expect(series.datasets[0].data).toEqual(['100.00'])
    })

    it('keeps every score under its own date, in the order given', () => {
        // Labels and scores are two separate maps over the same array, so the
        // pairing only holds while both keep the order they were given. Each
        // point is checked against the day it was recorded on: the day number is
        // read out of the label, whose wording follows the runner's locale.
        const history = [
            { created_at: '2026-07-04T12:00:00Z', score: 312.456 },
            { created_at: '2026-07-18T12:00:00Z', score: 318.1 },
            { created_at: '2026-08-02T12:00:00Z', score: 305.9 },
        ]

        const series = seriesOf(mount(WilksHistoryChart, { props: { data: history } }))
        const plotted = series.labels.map((label, index) => [label.match(/\d+/)[0], series.datasets[0].data[index]])

        expect(plotted).toEqual([
            ['04', '312.46'],
            ['18', '318.10'],
            ['02', '305.90'],
        ])
    })

    it('labels the points as day/month, never the raw timestamp', () => {
        const labels = seriesOf(mount(WilksHistoryChart, { props: { data: scores } })).labels

        labels.forEach((label) => {
            expect(label).not.toContain('T12:00:00Z')
            // `jj/mm`, comme tout axe de dates de l'application ; l'année,
            // toujours celle en cours sur un historique, reste hors de l'axe.
            expect(label).toMatch(/^\d{2}\/\d{2}$/)
            expect(label).not.toContain('2026')
        })
    })

    it('holds no point when no score has been computed yet', () => {
        const series = seriesOf(mount(WilksHistoryChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the history when a new score is added under it', async () => {
        const wrapper = mount(WilksHistoryChart, { props: { data: [scores[0]] } })

        await wrapper.setProps({ data: scores })

        expect(seriesOf(wrapper).datasets[0].data).toEqual(['312.46', '318.10'])
    })
})

describe('WilksHistoryChart reading', () => {
    it('names the score in the tooltip', () => {
        const options = optionsOf(mount(WilksHistoryChart, { props: { data: scores } }))

        expect(options.plugins.tooltip.callbacks.label({ parsed: { y: 312.46 } })).toBe('Score: 312.46')
    })

    it('keeps the score axis, since the figure means nothing without a scale', () => {
        const options = optionsOf(mount(WilksHistoryChart, { props: { data: scores } }))

        expect(options.scales.y.display).toBe(true)
        expect(options.scales.x.ticks.maxTicksLimit).toBe(6)
    })
})
