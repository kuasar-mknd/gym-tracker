import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const RecentWorkoutsDurationChart = (await import('@/Components/Stats/RecentWorkoutsDurationChart.vue')).default

// Newest first, as the dashboard sends it. Local-time strings on purpose: the
// calendar day of a `...Z` instant depends on where it is read, and what is
// under test here is the label format, not the timezone.
const newestFirst = [
    { started_at: '2026-07-31T18:00:00', ended_at: '2026-07-31T19:02:00' },
    { started_at: '2026-07-05T18:00:00', ended_at: '2026-07-05T19:30:00' },
]

const mountChart = (data = newestFirst) => mount(RecentWorkoutsDurationChart, { props: { data } })

describe('RecentWorkoutsDurationChart series', () => {
    it('reads chronologically, oldest session on the left, dated in French', () => {
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.labels).toEqual(['5 juil.', '31 juil.'])
        expect(series.datasets[0].label).toBe('Durée (min)')
        expect(series.datasets[0].data).toEqual([90, 62])
    })

    it('rounds a duration to whole minutes', () => {
        // started_at and ended_at carry seconds. "62.35 min" on a hover is
        // noise; the card is read to the minute.
        const series = chartDataOf(
            mountChart([{ started_at: '2026-07-31T18:00:00', ended_at: '2026-07-31T19:02:21' }]),
            'Line',
        )

        expect(series.datasets[0].data).toEqual([62])
    })

    it('does not reverse the page prop it was handed', () => {
        const source = [...newestFirst]

        mountChart(source)

        expect(source.map((workout) => workout.started_at)).toEqual(['2026-07-31T18:00:00', '2026-07-05T18:00:00'])
    })

    it('draws nothing rather than throwing before the first session', () => {
        const series = chartDataOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('RecentWorkoutsDurationChart readouts', () => {
    it('names the unit on hover', () => {
        expect(tooltipLabelOf(mountChart(), 'Line', { parsed: { y: 62 } })).toBe('62 min')
    })

    it('starts the axis at zero, because a duration is a quantity from nothing', () => {
        expect(chartOptionsOf(mountChart(), 'Line').scales.y.beginAtZero).toBe(true)
    })
})
