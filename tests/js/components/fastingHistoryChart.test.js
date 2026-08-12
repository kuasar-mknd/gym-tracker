import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const FastingHistoryChart = (await import('@/Components/Stats/FastingHistoryChart.vue')).default

const mountChart = (data) => mount(FastingHistoryChart, { props: { data } })

/** A 16 h 30 fast, the shape the fasting page sends. */
const completedFast = {
    start_time: '2026-01-05T20:00:00Z',
    end_time: '2026-01-06T12:30:00Z',
}

describe('FastingHistoryChart', () => {
    it('mesure la durée en heures, à la décimale', () => {
        const chart = chartDataOf(mountChart([completedFast]), 'Bar')

        expect(chart.datasets[0].data).toEqual(['16.5'])
    })

    it('arrondit une durée qui ne tombe pas juste', () => {
        // 3 h 20 min = 3,333… h, et non 3,3 h par troncature.
        const chart = chartDataOf(
            mountChart([{ start_time: '2026-01-05T08:00:00Z', end_time: '2026-01-05T11:20:00Z' }]),
            'Bar',
        )

        expect(chart.datasets[0].data).toEqual(['3.3'])
    })

    it('laisse le jeûne en cours hors du graphique', () => {
        // Sans end_time il n'y a pas de durée. La barre serait fausse tant que
        // le jeûne dure, et l'utilisateur la lirait comme un jeûne terminé.
        const chart = chartDataOf(
            mountChart([completedFast, { start_time: '2026-01-07T20:00:00Z', end_time: null }]),
            'Bar',
        )

        expect(chart.labels).toHaveLength(1)
        expect(chart.datasets[0].data).toEqual(['16.5'])
    })

    it('trace du plus ancien au plus récent, quel que soit l’ordre reçu', () => {
        const chart = chartDataOf(
            mountChart([
                { start_time: '2026-03-10T12:00:00Z', end_time: '2026-03-10T22:00:00Z' },
                { start_time: '2026-01-05T12:00:00Z', end_time: '2026-01-05T20:00:00Z' },
                { start_time: '2026-02-01T12:00:00Z', end_time: '2026-02-01T21:00:00Z' },
            ]),
            'Bar',
        )

        expect(chart.datasets[0].data).toEqual(['8.0', '9.0', '10.0'])
        expect(chart.labels).toEqual(['05/01', '01/02', '10/03'])
    })

    it('étiquette les barres en jour/mois à la française', () => {
        const chart = chartDataOf(mountChart([completedFast]), 'Bar')

        expect(chart.labels).toEqual(['05/01'])
    })

    it('affiche les heures dans l’infobulle', () => {
        const wrapper = mountChart([completedFast])

        expect(tooltipLabelOf(wrapper, 'Bar', { parsed: { y: '16.5' } })).toBe('16.5 h')
    })

    it('ne trace rien quand aucun jeûne n’est terminé', () => {
        const chart = chartDataOf(mountChart([{ start_time: '2026-01-07T20:00:00Z', end_time: null }]), 'Bar')

        expect(chart.labels).toEqual([])
        expect(chart.datasets[0].data).toEqual([])
    })
})
