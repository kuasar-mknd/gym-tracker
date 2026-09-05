import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const DashboardDurationChart = (await import('@/Components/Stats/DashboardDurationChart.vue')).default

const mountChart = (data) => mount(DashboardDurationChart, { props: { data } })

/**
 * The zero-for-an-unfinished-session defect this chart shared with three others
 * is covered in chartAxes.test.js, next to theirs. What is left here is what
 * only this component does: the reversal and the French day labels.
 */
const workout = (startedAt, endedAt) => ({ started_at: startedAt, ended_at: endedAt })

describe('DashboardDurationChart', () => {
    it('renverse l’historique descendant pour se lire de gauche à droite', () => {
        // The dashboard sends the most recent session first; drawn that way the
        // curve of a user getting steadily faster would read as getting slower.
        const chart = chartDataOf(
            mountChart([
                workout('2026-07-31T12:00:00Z', '2026-07-31T13:30:00Z'),
                workout('2026-07-30T12:00:00Z', '2026-07-30T13:00:00Z'),
                workout('2026-07-29T12:00:00Z', '2026-07-29T12:45:00Z'),
            ]),
            'Line',
        )

        expect(chart.datasets[0].data).toEqual([45, 60, 90])
        expect(chart.labels).toEqual(['29/07', '30/07', '31/07'])
    })

    it('étiquette les points en jour/mois', () => {
        const chart = chartDataOf(mountChart([workout('2026-01-05T12:00:00Z', '2026-01-05T13:00:00Z')]), 'Line')

        expect(chart.labels).toEqual(['05/01'])
    })

    it('donne l’unité dans l’infobulle', () => {
        const wrapper = mountChart([workout('2026-07-31T12:00:00Z', '2026-07-31T13:30:00Z')])

        expect(tooltipLabelOf(wrapper, 'Line', { parsed: { y: 90 } })).toBe('90 min')
    })

    it('ne réordonne pas le tableau que la page lui a passé', () => {
        const workouts = [
            workout('2026-07-31T12:00:00Z', '2026-07-31T13:30:00Z'),
            workout('2026-07-30T12:00:00Z', '2026-07-30T13:00:00Z'),
        ]

        mountChart(workouts)

        expect(workouts.map((entry) => entry.started_at)).toEqual(['2026-07-31T12:00:00Z', '2026-07-30T12:00:00Z'])
    })
})
