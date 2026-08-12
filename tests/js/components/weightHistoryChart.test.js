import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WeightHistoryChart = (await import('@/Components/Stats/WeightHistoryChart.vue')).default

const readings = [
    { date: '01/07', weight: 78.4 },
    { date: '15/07', weight: 77.9 },
    { date: '29/07', weight: 77.2 },
]

const mountChart = (data = readings) => mount(WeightHistoryChart, { props: { data } })

describe('WeightHistoryChart series', () => {
    it('plots each weigh-in against its own date, in the order it was given', () => {
        // Unlike the history charts beside it this one is handed an already
        // chronological list. Reversing it here would draw the whole curve
        // backwards — a cut would read as a bulk.
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.labels).toEqual(['01/07', '15/07', '29/07'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Poids (kg)')
        expect(series.datasets[0].data).toEqual([78.4, 77.9, 77.2])
    })

    it('keeps the tenth of a kilo the scale reported', () => {
        // A cut moves 0.3 kg a week. Rounding here would flatten a whole month
        // into a step function.
        const series = chartDataOf(mountChart([{ date: '01/07', weight: 82.5 }]), 'Line')

        expect(series.datasets[0].data).toEqual([82.5])
    })

    it('plots no points at all before the first weigh-in', () => {
        const series = chartDataOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the history when a weigh-in is added', async () => {
        const wrapper = mountChart(readings.slice(0, 2))

        await wrapper.setProps({ data: readings })

        expect(chartDataOf(wrapper, 'Line').datasets[0].data).toEqual([78.4, 77.9, 77.2])
    })
})

describe('WeightHistoryChart readouts', () => {
    it('names the unit on hover, because the x axis is hidden', () => {
        expect(tooltipLabelOf(mountChart(), 'Line', { parsed: { y: 77.2 } })).toBe('77.2 kg')
    })

    it('does not anchor the axis at zero', () => {
        // A body weight sits around 80 kg and moves by ones. Drawn from zero
        // the curve is a flat line and the chart says nothing.
        const y = chartOptionsOf(mountChart(), 'Line').scales.y

        expect(y.beginAtZero).toBeUndefined()
        expect(y.display).toBe(true)
    })
})

describe('WeightHistoryChart gradients', () => {
    it('asks for no colour at all before the plot area exists', () => {
        // Resolved once on the first frame, before layout: without the guard
        // this reads `.left` off undefined and blanks the page.
        const dataset = chartDataOf(mountChart(), 'Line').datasets[0]
        const blankChart = { chart: { ctx: null, chartArea: null } }

        expect(dataset.borderColor(blankChart)).toBeNull()
        expect(dataset.backgroundColor(blankChart)).toBeNull()
    })
})
