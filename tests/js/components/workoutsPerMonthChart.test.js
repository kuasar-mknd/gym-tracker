import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WorkoutsPerMonthChart = (await import('@/Components/Stats/WorkoutsPerMonthChart.vue')).default

const mountChart = (data) => mount(WorkoutsPerMonthChart, { props: { data } })

/** The workouts page sends the month already formatted, with its session count. */
const months = [
    { month: 'Mai', count: 12 },
    { month: 'Juin', count: 8 },
    { month: 'Juil', count: 15 },
]

describe('WorkoutsPerMonthChart', () => {
    it('place chaque compte de séances sur son mois, dans l’ordre reçu', () => {
        const chart = chartDataOf(mountChart(months), 'Bar')

        expect(chart.labels).toEqual(['Mai', 'Juin', 'Juil'])
        expect(chart.datasets[0].data).toEqual([12, 8, 15])
        expect(chart.datasets[0].label).toBe('Séances')
    })

    it('gradue l’axe en séances entières, depuis zéro', () => {
        // A session is one or none. A 0,5 gridline, or an axis starting at 8,
        // would misstate a month against the one before it.
        const yAxis = chartOptionsOf(mountChart(months), 'Bar').scales.y

        expect(yAxis.ticks.stepSize).toBe(1)
        expect(yAxis.beginAtZero).toBe(true)
    })
})
