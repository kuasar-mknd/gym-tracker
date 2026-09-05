import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WorkoutFrequencyChart = (await import('@/Components/Stats/WorkoutFrequencyChart.vue')).default

const week = [
    { day: 'Lun', count: 2 },
    { day: 'Mar', count: 0 },
    { day: 'Mer', count: 1 },
    { day: 'Jeu', count: 0 },
    { day: 'Ven', count: 3 },
]

const mountChart = (data = week) => mount(WorkoutFrequencyChart, { props: { data } })

describe('WorkoutFrequencyChart series', () => {
    it('plots each day against its own count, in the order it was given', () => {
        // The controller sends the week already in weekday order. Sorting or
        // reversing here would put Friday's sessions on Monday.
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven'])
        expect(series.datasets).toHaveLength(1)
        expect(series.datasets[0].label).toBe('Séances')
        expect(series.datasets[0].data).toEqual([2, 0, 1, 0, 3])
    })

    it('keeps the rest days in the series rather than dropping them', () => {
        // A day with no session is a zero-height bar, not a missing column.
        // Filtering the zeros out would slide every later day to the left.
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toHaveLength(series.datasets[0].data.length)
        expect(series.datasets[0].data.filter((count) => count === 0)).toHaveLength(2)
    })

    it('draws no bars at all for a week with no data', () => {
        const series = chartDataOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('WorkoutFrequencyChart readouts', () => {
    it('names what is being counted on hover, and draws the y axis like its neighbours', () => {
        const wrapper = mountChart()

        expect(chartOptionsOf(wrapper, 'Bar').scales.y.display).toBe(true)
        expect(tooltipLabelOf(wrapper, 'Bar', { parsed: { y: 3 } })).toBe('3 séances')
    })

    it('starts the bars at zero', () => {
        // With the axis hidden the bar heights are the only quantity on show,
        // so a floating baseline would make two sessions look like six.
        expect(chartOptionsOf(mountChart(), 'Bar').scales.y.beginAtZero).toBe(true)
    })
})

describe('WorkoutFrequencyChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const dataset = chartDataOf(mountChart(), 'Bar').datasets[0]

        expect(dataset.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
