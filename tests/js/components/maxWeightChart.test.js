import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const MaxWeightChart = (await import('@/Components/Stats/MaxWeightChart.vue')).default

const sessions = [
    { date: '01/07', weight: 80 },
    { date: '08/07', weight: 82.5 },
    { date: '15/07', weight: 85 },
]

const mountChart = (data = sessions) => mount(MaxWeightChart, { props: { data } })

describe('MaxWeightChart series', () => {
    it('plots each session against its own top set, in the order it was given', () => {
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.labels).toEqual(['01/07', '08/07', '15/07'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Charge Max (kg)')
        expect(series.datasets[0].data).toEqual([80, 82.5, 85])
    })

    it('keeps the half-plate increment', () => {
        // 82.5 kg is a real bar loading — 80 plus a 1.25 plate a side. Rounding
        // it away here would hide every micro-progression on the card.
        expect(chartDataOf(mountChart(), 'Line').datasets[0].data[1]).toBe(82.5)
    })

    it('plots no points at all for an exercise never performed', () => {
        const series = chartDataOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the history when a session is logged', async () => {
        const wrapper = mountChart(sessions.slice(0, 2))

        await wrapper.setProps({ data: sessions })

        expect(chartDataOf(wrapper, 'Line').datasets[0].data).toEqual([80, 82.5, 85])
    })
})

describe('MaxWeightChart readouts', () => {
    it('names the unit on hover', () => {
        expect(tooltipLabelOf(mountChart(), 'Line', { parsed: { y: 82.5 } })).toBe('82.5 kg')
    })

    it('does not anchor the axis at zero', () => {
        // Top sets sit in a narrow band far from zero; drawn from the floor a
        // 5 kg gain over a month is invisible.
        expect(chartOptionsOf(mountChart(), 'Line').scales.y.beginAtZero).toBeUndefined()
    })
})

describe('MaxWeightChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Line').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
