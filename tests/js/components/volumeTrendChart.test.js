import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const VolumeTrendChart = (await import('@/Components/Stats/VolumeTrendChart.vue')).default

const days = [
    { date: '01/07', volume: 5000 },
    { date: '02/07', volume: 7200 },
    { date: '03/07', volume: 6100 },
]

const mountChart = (data = days) => mount(VolumeTrendChart, { props: { data } })

describe('VolumeTrendChart series', () => {
    it('plots each day against its own volume, in the order it was given', () => {
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['01/07', '02/07', '03/07'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Volume (kg)')
        expect(series.datasets[0].data).toEqual([5000, 7200, 6100])
    })

    it('draws no bars at all when nothing has been lifted yet', () => {
        const series = chartDataOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the period when it is switched under the chart', async () => {
        const wrapper = mountChart()

        await wrapper.setProps({ data: days.slice(0, 1) })

        expect(chartDataOf(wrapper, 'Bar').labels).toEqual(['01/07'])
        expect(chartDataOf(wrapper, 'Bar').datasets[0].data).toEqual([5000])
    })
})

describe('VolumeTrendChart axis', () => {
    it('starts the bars at zero', () => {
        // A bar chart whose baseline floats exaggerates every difference:
        // 5000 kg and 7200 kg become a stub and a bar three times taller.
        expect(chartOptionsOf(mountChart(), 'Bar').scales.y.beginAtZero).toBe(true)
    })

    it('shortens kilogrammes past a thousand without losing the half', () => {
        // Shared with MonthlyVolumeChart via Utils/volumeAxis; asserted here
        // too because the wiring is what the user sees, and this axis is the
        // one the shared rule was written for.
        const tick = chartOptionsOf(mountChart(), 'Bar').scales.y.ticks.callback

        expect(tick(1500)).toBe('1.5k')
        expect(tick(2000)).toBe('2.0k')
        expect(tick(750)).toBe(750)
    })
})

describe('VolumeTrendChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Bar').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
