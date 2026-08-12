import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const HistoryChart = (await import('@/Components/Stats/HistoryChart.vue')).default

// The exercise history endpoint hands back the newest session first.
const newestFirst = [
    { formatted_date: '31 juil.', best_1rm: 104.6 },
    { formatted_date: '17 juil.', best_1rm: 102.5 },
    { formatted_date: '05 juil.', best_1rm: 100.4 },
]

const mountChart = (data = newestFirst) => mount(HistoryChart, { props: { data } })

describe('HistoryChart series', () => {
    it('reads chronologically, oldest session on the left', () => {
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.labels).toEqual(['05 juil.', '17 juil.', '31 juil.'])
    })

    it('rounds the estimated 1RM to the kilo the user reads', () => {
        // best_1rm arrives from the Epley estimate carrying full float noise
        // (100.40000000000002). A 1RM is announced in kilos.
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.datasets[0].label).toBe('Meilleur 1RM (kg)')
        expect(series.datasets[0].data).toEqual([100, 103, 105])
    })

    it('keeps every value paired with its own date after the flip', () => {
        // The labels and the values are mapped in two separate passes; both
        // have to walk the same reversed array or the whole curve is misdated.
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.labels.indexOf('31 juil.')).toBe(series.datasets[0].data.indexOf(105))
    })

    it('does not reverse the page prop it was handed', () => {
        const source = [...newestFirst]

        mountChart(source)

        expect(source.map((session) => session.formatted_date)).toEqual(['31 juil.', '17 juil.', '05 juil.'])
    })

    it('plots no points at all for an exercise never performed', () => {
        const series = chartDataOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('HistoryChart axis', () => {
    const suggested = (values) => {
        const y = chartOptionsOf(mountChart(), 'Line').scales.y
        const context = { chart: { data: { datasets: [{ data: values }] } } }

        return [y.suggestedMin(context), y.suggestedMax(context)]
    }

    it('frames the 1RM band instead of crushing it against zero', () => {
        // A 1RM moves from 100 to 105 kg over months. Anchored at zero that is
        // a flat line, and the whole point of the card is the 5 kg.
        const [min, max] = suggested([100, 103, 105])

        expect(chartOptionsOf(mountChart(), 'Line').scales.y.beginAtZero).toBe(false)
        expect(min).toBeCloseTo(90, 6)
        expect(max).toBeCloseTo(115.5, 6)
    })

    it('still leaves a band around a single session', () => {
        // One session is the normal case for a newly added exercise: min and
        // max are the same number, and without the margin the only point sits
        // on both edges of the plot at once.
        const [min, max] = suggested([100])

        expect(min).toBeCloseTo(90, 6)
        expect(max).toBeCloseTo(110, 6)
    })

    it('reads the band off the plotted values, not the raw props', () => {
        // The callbacks take the values Chart.js holds, which are the rounded
        // ones. Reading props.data here would drift from what is drawn.
        const [min, max] = suggested([80, 120])

        expect(min).toBeCloseTo(72, 6)
        expect(max).toBeCloseTo(132, 6)
    })
})

describe('HistoryChart readouts', () => {
    it('names the unit on hover, because the y axis is not drawn at all', () => {
        expect(chartOptionsOf(mountChart(), 'Line').scales.y.display).toBe(false)
        expect(tooltipLabelOf(mountChart(), 'Line', { parsed: { y: 105 } })).toBe('105 kg')
    })
})

describe('HistoryChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Line').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
