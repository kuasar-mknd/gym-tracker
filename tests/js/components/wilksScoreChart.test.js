import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WilksScoreChart = (await import('@/Components/Stats/WilksScoreChart.vue')).default

const mountChart = (data) => mount(WilksScoreChart, { props: { data } })

describe('WilksScoreChart', () => {
    it('trace l’historique du plus ancien au plus récent, quel que soit l’ordre reçu', () => {
        const wrapper = mountChart([
            { created_at: '2026-03-10T12:00:00Z', score: 320.5 },
            { created_at: '2026-01-05T12:00:00Z', score: 300 },
            { created_at: '2026-02-01T12:00:00Z', score: 310.25 },
        ])

        const chart = chartDataOf(wrapper, 'Line')

        expect(chart.datasets[0].data).toEqual([300, 310.25, 320.5])
        expect(chart.labels).toEqual(['05/01', '01/02', '10/03'])
    })

    it('ne réordonne pas le tableau que la page lui a passé', () => {
        // The sort is on a copy. Sorting in place would reorder the array the
        // Wilks page also renders as a table, under the chart.
        const history = [
            { created_at: '2026-03-10T12:00:00Z', score: 320.5 },
            { created_at: '2026-01-05T12:00:00Z', score: 300 },
        ]

        mountChart(history)

        expect(history.map((entry) => entry.score)).toEqual([320.5, 300])
    })

    it('trace des nombres à partir des scores rendus en chaîne par la base', () => {
        const chart = chartDataOf(mountChart([{ created_at: '2026-01-05T12:00:00Z', score: '312.47' }]), 'Line')

        expect(chart.datasets[0].data).toEqual([312.47])
        expect(typeof chart.datasets[0].data[0]).toBe('number')
    })

    it('annonce le score au centième dans l’infobulle', () => {
        const wrapper = mountChart([{ created_at: '2026-01-05T12:00:00Z', score: 320.5 }])

        expect(tooltipLabelOf(wrapper, 'Line', { parsed: { y: 320.5 } })).toBe('Score: 320.50')
        expect(tooltipLabelOf(wrapper, 'Line', { parsed: { y: 300 } })).toBe('Score: 300.00')
    })
})
