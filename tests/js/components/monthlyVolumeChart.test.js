import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const MonthlyVolumeChart = (await import('@/Components/Stats/MonthlyVolumeChart.vue')).default

const months = [
    { month: 'Mai', volume: 41000 },
    { month: 'Juin', volume: 52000 },
    { month: 'Juil', volume: 48500 },
]

const mountChart = (data = months) => mount(MonthlyVolumeChart, { props: { data } })

describe('MonthlyVolumeChart series', () => {
    it('plots each month against its own volume, in the order it was given', () => {
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['Mai', 'Juin', 'Juil'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Volume (kg)')
        expect(series.datasets[0].data).toEqual([41000, 52000, 48500])
    })

    it('draws no bars at all in a first month with nothing logged', () => {
        const series = chartDataOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the range when it is switched under the chart', async () => {
        const wrapper = mountChart()

        await wrapper.setProps({ data: months.slice(2) })

        expect(chartDataOf(wrapper, 'Bar').labels).toEqual(['Juil'])
        expect(chartDataOf(wrapper, 'Bar').datasets[0].data).toEqual([48500])
    })
})

describe('MonthlyVolumeChart axis', () => {
    it('starts the bars at zero', () => {
        // Monthly volumes cluster in a narrow band well above zero. A floating
        // baseline would turn a 5 % month-on-month move into a doubled bar.
        expect(chartOptionsOf(mountChart(), 'Bar').scales.y.beginAtZero).toBe(true)
    })

    it('shortens kilogrammes past a thousand without losing the half', () => {
        // The defect this axis was fixed for: toFixed(0) labelled 1500 as "2k"
        // and the axis read "1k, 2k, 2k, 3k, 3k". Now shared with
        // VolumeTrendChart through Utils/volumeAxis.
        const tick = chartOptionsOf(mountChart(), 'Bar').scales.y.ticks.callback

        expect(tick(1500)).toBe('1.5k')
        expect(tick(2000)).toBe('2.0k')
        expect(tick(750)).toBe(750)
    })
})

describe('MonthlyVolumeChart tooltip', () => {
    it('groups the thousands in a monthly volume', () => {
        // A month runs to six figures. "125000 kg" cannot be sized up at a
        // glance; the separator is what makes it a number. The separator
        // itself is the reader's locale, so only the grouping is asserted.
        const label = tooltipLabelOf(mountChart(), 'Bar', { raw: 125000 })

        expect(label).not.toBe('125000 kg')
        expect(label).toMatch(/^125\D000 kg$/)
    })

    it('reads the raw value, not the shortened axis label', () => {
        // The axis says "48.5k"; the hover has to say the whole number, or the
        // card cannot be used to check a month against a target.
        expect(tooltipLabelOf(mountChart(), 'Bar', { raw: 48500 })).toMatch(/^48\D500 kg$/)
    })

    it('survives a volume arriving as a string from the API', () => {
        // Sums come back from MySQL as decimal strings; "48500".toLocaleString()
        // is a no-op, which is why the callback casts before formatting.
        expect(tooltipLabelOf(mountChart(), 'Bar', { raw: '48500' })).toMatch(/^48\D500 kg$/)
    })
})

describe('MonthlyVolumeChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Bar').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
