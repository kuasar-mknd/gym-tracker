import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real components
 * reach for a canvas that jsdom does not draw, and what these tests need is the
 * data and options handed to the chart — which is exactly where each of these
 * defects lives.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const MonthlyVolumeChart = (await import('@/Components/Stats/MonthlyVolumeChart.vue')).default
const WaterHistoryChart = (await import('@/Components/Stats/WaterHistoryChart.vue')).default
const DashboardDurationChart = (await import('@/Components/Stats/DashboardDurationChart.vue')).default
const RecentWorkoutsTimelineChart = (await import('@/Components/Stats/RecentWorkoutsTimelineChart.vue')).default
const WorkoutHistoryTimelineChart = (await import('@/Components/Stats/WorkoutHistoryTimelineChart.vue')).default

const chartIn = (wrapper, name) => wrapper.findComponent({ name })

describe('MonthlyVolumeChart Y axis', () => {
    const tickLabel = (value) => {
        const wrapper = mount(MonthlyVolumeChart, { props: { data: [{ month: 'Juil', volume: 1500 }] } })

        return chartIn(wrapper, 'Bar').props('options').scales.y.ticks.callback(value)
    }

    it('does not label 1500 kg as 2k', () => {
        // toFixed(0) rounded to the thousand before adding the k, so with the
        // 500 kg step Chart.js picks for smaller volumes the axis read
        // "1k, 2k, 2k, 3k, 3k" — duplicated labels, each 500 kg out.
        expect(tickLabel(1500)).toBe('1.5k')
    })

    it('still labels round thousands cleanly', () => {
        expect(tickLabel(2000)).toBe('2.0k')
    })

    it('leaves values under a thousand alone', () => {
        expect(tickLabel(750)).toBe(750)
    })
})

describe('WaterHistoryChart Y axis', () => {
    it('leaves room above the day it was given', () => {
        const wrapper = mount(WaterHistoryChart, {
            props: { data: [{ day_name: 'Vendredi', total: 1000 }], goal: 2500 },
        })

        expect(chartIn(wrapper, 'Bar').props('options').scales.y.max).toBe(2700)
    })

    /**
     * chartOptions was a plain object evaluated once at setup, and an Inertia
     * visit reuses the component instance, so the maximum stayed on the first
     * render's props. Adding water past it clipped the bar while the ring above
     * announced the real total.
     */
    it('follows the total up when more water is logged', async () => {
        const wrapper = mount(WaterHistoryChart, {
            props: { data: [{ day_name: 'Vendredi', total: 1000 }], goal: 2500 },
        })

        await wrapper.setProps({ data: [{ day_name: 'Vendredi', total: 3000 }] })

        expect(chartIn(wrapper, 'Bar').props('options').scales.y.max).toBe(3200)
    })
})

/**
 * All three, not just the one. Each had written the same zero-for-unfinished
 * expression by hand, so testing a single component would have left the other
 * two free to drift back — the same gap the calendar-day guard exists for.
 *
 * The duration dataset is found by its label rather than its index: one of
 * these charts draws a line over bars and its durations are not dataset zero.
 */
describe.each([
    ['DashboardDurationChart', DashboardDurationChart, 'Line'],
    ['RecentWorkoutsTimelineChart', RecentWorkoutsTimelineChart, 'Line'],
    ['WorkoutHistoryTimelineChart', WorkoutHistoryTimelineChart, 'Bar'],
])('%s', (_name, component, chartType) => {
    it('plots nothing for the session still running rather than a zero', () => {
        const wrapper = mount(component, {
            props: {
                data: [
                    { started_at: '2026-07-31T10:00:00Z', ended_at: null, workout_volume: 100 },
                    { started_at: '2026-07-30T10:00:00Z', ended_at: '2026-07-30T11:00:00Z', workout_volume: 200 },
                ],
            },
        })

        const durations = chartIn(wrapper, chartType)
            .props('data')
            .datasets.find((dataset) => dataset.label === 'Durée (min)')

        // Every one of them reverses its input to read chronologically, so the
        // finished session comes first and the running one last. null is what
        // Chart.js skips; 0 is what it plots on the axis.
        expect(durations.data).toEqual([60, null])
    })
})
