import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WorkoutHistoryTimelineChart = (await import('@/Components/Stats/WorkoutHistoryTimelineChart.vue')).default

// The history endpoint hands back the newest session first. Local-time strings
// on purpose: the calendar day of a `...Z` instant depends on where it is read,
// and the label rule under test is the formatting, not the timezone.
const newestFirst = [
    { started_at: '2026-07-31T18:00:00', ended_at: '2026-07-31T19:02:00', workout_volume: 5200 },
    { started_at: '2026-07-05T18:00:00', ended_at: '2026-07-05T19:30:00', workout_volume: 4800 },
]

const mountChart = (data = newestFirst) => mount(WorkoutHistoryTimelineChart, { props: { data } })

describe('WorkoutHistoryTimelineChart series', () => {
    it('reads chronologically, oldest session on the left, dated in French', () => {
        const series = chartDataOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['05/07', '31/07'])
    })

    it('keeps the duration line on its own axis, above the volume bars', () => {
        // The two series share one plot and their units have nothing in common:
        // 90 minutes read against 5200 kg flattens the line onto the floor.
        // The line reads y1, the bars read y.
        const [duration, volume] = chartDataOf(mountChart(), 'Bar').datasets

        expect([duration.label, duration.type, duration.yAxisID]).toEqual(['Durée (min)', 'line', 'y1'])
        expect([volume.label, volume.type, volume.yAxisID]).toEqual(['Volume (kg)', 'bar', 'y'])
        expect(duration.data).toEqual([90, 62])
        expect(volume.data).toEqual([4800, 5200])
    })

    it('reads a session with nothing logged as zero volume rather than a gap', () => {
        // workout_volume is absent on a session where no set was saved. A hole
        // in a bar chart looks like a missing session; zero is the honest bar
        // — unlike the duration, which genuinely was not measured.
        const series = chartDataOf(
            mountChart([{ started_at: '2026-07-31T18:00:00', ended_at: '2026-07-31T19:02:00' }]),
            'Bar',
        )

        expect(series.datasets[1].data).toEqual([0])
        expect(series.datasets[0].data).toEqual([62])
    })

    it('does not reverse the page prop it was handed', () => {
        const source = [...newestFirst]

        mountChart(source)

        expect(source.map((workout) => workout.started_at)).toEqual(['2026-07-31T18:00:00', '2026-07-05T18:00:00'])
    })

    it('draws nothing rather than throwing before the first session', () => {
        const series = chartDataOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
        expect(series.datasets[1].data).toEqual([])
    })
})

describe('WorkoutHistoryTimelineChart tooltip', () => {
    it('puts minutes on the line and kilos on the bars', () => {
        // The unit is picked by dataset index, so the two readouts are only
        // right for as long as index 0 really is the duration line. The
        // pairing is the assertion, not each half on its own.
        const wrapper = mountChart()
        const datasets = chartDataOf(wrapper, 'Bar').datasets
        const label = chartOptionsOf(wrapper, 'Bar').plugins.tooltip.callbacks.label

        const readout = (datasetIndex, y) => label({ dataset: datasets[datasetIndex], datasetIndex, parsed: { y } })

        expect(readout(0, 62)).toBe('Durée (min): 62 min')
        expect(readout(1, 5200)).toBe('Volume (kg): 5200 kg')
    })
})

describe('WorkoutHistoryTimelineChart axes', () => {
    it('starts both scales at zero', () => {
        // Neither axis is drawn, so bar height and line height are the only
        // quantities on show; a floating baseline on either would misstate them.
        const scales = chartOptionsOf(mountChart(), 'Bar').scales

        expect(scales.y.beginAtZero).toBe(true)
        expect(scales.y1.beginAtZero).toBe(true)
        expect(scales.y1.position).toBe('right')
    })
})

describe('WorkoutHistoryTimelineChart gradient', () => {
    it('asks for no colour at all before the plot area exists', () => {
        const volume = chartDataOf(mountChart(), 'Bar').datasets[1]

        expect(volume.backgroundColor({ chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
