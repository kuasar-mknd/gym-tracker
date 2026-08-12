import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const SupplementIntakeChart = (await import('@/Components/Stats/SupplementIntakeChart.vue')).default

const days = [
    { date: '01/07', count: 3 },
    { date: '02/07', count: 0 },
    { date: '03/07', count: 1 },
]

const mountChart = (data = days) => mount(SupplementIntakeChart, { props: { data } })

describe('SupplementIntakeChart series', () => {
    it('plots each day against its own dose count, in the order it was given', () => {
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['01/07', '02/07', '03/07'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Doses')
        expect(series.datasets[0].data).toEqual([3, 0, 1])
    })

    it('keeps the missed day in the series rather than dropping it', () => {
        // The point of an adherence chart is the gaps. Filtering the zeros out
        // would slide the later days left and hide the day that was missed.
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.datasets[0].data[1]).toBe(0)
        expect(series.labels[1]).toBe('02/07')
    })

    it('draws no bars at all before any supplement is tracked', () => {
        const series = chartDataOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('SupplementIntakeChart axis', () => {
    it('never offers half a dose on the axis', () => {
        // Doses are counted, not measured. Left to itself Chart.js steps a
        // 0-to-3 axis by 0.5 and the gridlines read 0.5, 1.5, 2.5 doses.
        expect(chartOptionsOf(mountChart(), 'Bar').scales.y.ticks.precision).toBe(0)
    })

    it('starts the bars at zero', () => {
        expect(chartOptionsOf(mountChart(), 'Bar').scales.y.beginAtZero).toBe(true)
    })
})

describe('SupplementIntakeChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Bar').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
