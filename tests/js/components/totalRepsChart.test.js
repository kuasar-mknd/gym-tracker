import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const TotalRepsChart = (await import('@/Components/Stats/TotalRepsChart.vue')).default

const mountChart = (data) => mount(TotalRepsChart, { props: { data } })

/** The exercise page sends days already reduced to dd/mm and their rep total. */
const history = [
    { date: '28/04', reps: 24 },
    { date: '05/05', reps: 30 },
    { date: '12/05', reps: 18 },
]

describe('TotalRepsChart', () => {
    it('place chaque total de répétitions sur son jour, dans l’ordre reçu', () => {
        const chart = chartDataOf(mountChart(history), 'Bar')

        expect(chart.labels).toEqual(['28/04', '05/05', '12/05'])
        expect(chart.datasets[0].data).toEqual([24, 30, 18])
    })

    it('nomme la série par ce qu’elle mesure', () => {
        expect(chartDataOf(mountChart(history), 'Bar').datasets[0].label).toBe('Volume (Reps)')
    })

    it('compte les répétitions dans l’infobulle', () => {
        expect(tooltipLabelOf(mountChart(history), 'Bar', { parsed: { y: 30 } })).toBe('30 reps')
    })

    it('part de zéro, pour que la hauteur des barres soit lisible comme un rapport', () => {
        // Truncated at the bottom, 24 reps against 30 looks like a third of the
        // work rather than four fifths.
        expect(chartOptionsOf(mountChart(history), 'Bar').scales.y.beginAtZero).toBe(true)
    })
})
