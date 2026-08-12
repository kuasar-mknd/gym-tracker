import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const Estimated1RMHistoryChart = (await import('@/Components/Stats/Estimated1RMHistoryChart.vue')).default

const mountChart = (data) => mount(Estimated1RMHistoryChart, { props: { data } })

/** The exercise page sends days already reduced to dd/mm and the estimated 1RM. */
const history = [
    { date: '28/04', weight: 96.5 },
    { date: '05/05', weight: 100 },
    { date: '12/05', weight: 102.5 },
]

describe('Estimated1RMHistoryChart', () => {
    it('place chaque 1RM estimé sur son jour, dans l’ordre reçu', () => {
        const chart = chartDataOf(mountChart(history), 'Line')

        expect(chart.labels).toEqual(['28/04', '05/05', '12/05'])
        expect(chart.datasets[0].data).toEqual([96.5, 100, 102.5])
    })

    it('annonce une estimation, pas un poids soulevé', () => {
        // The curve is Epley's estimate. Labelled "1RM (kg)" it would read as a
        // maximum the user actually lifted.
        expect(chartDataOf(mountChart(history), 'Line').datasets[0].label).toBe('1RM Estimé (kg)')
    })

    it('donne l’unité dans l’infobulle', () => {
        expect(tooltipLabelOf(mountChart(history), 'Line', { parsed: { y: 102.5 } })).toBe('102.5 kg')
    })
})
