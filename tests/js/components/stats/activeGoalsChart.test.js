import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is what the
 * bars claim — the titles, the rounded progress and the fixed 0-100 scale.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const ActiveGoalsChart = (await import('@/Components/Stats/ActiveGoalsChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('options')

const goals = [
    { title: 'Développé couché 100 kg', progress_pct: 62.4 },
    { title: 'Squat 140', progress_pct: 91.5 },
]

describe('ActiveGoalsChart labels', () => {
    it('cuts a title too long for the axis, and marks it as cut', () => {
        const series = seriesOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        expect(series.labels[0]).toBe('Développé couch...')
        expect(series.labels[0].replace('...', '')).toHaveLength(15)
    })

    it('leaves a title that fits whole, with no ellipsis to explain', () => {
        const series = seriesOf(
            mount(ActiveGoalsChart, { props: { data: [{ title: 'Quinze caractè', progress_pct: 10 }] } }),
        )

        expect(series.labels).toEqual(['Quinze caractè'])
    })

    it('gives the tooltip back the whole title the axis had to cut', () => {
        const options = optionsOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        expect(options.plugins.tooltip.callbacks.title([{ dataIndex: 0 }])).toBe('Développé couché 100 kg')
        expect(options.plugins.tooltip.callbacks.title([{ dataIndex: 1 }])).toBe('Squat 140')
    })

    it('keeps each bar under its own title', () => {
        const series = seriesOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        expect(series.labels[1]).toBe('Squat 140')
        expect(series.datasets[0].data[1]).toBe(92)
    })
})

describe('ActiveGoalsChart progress', () => {
    it('rounds the progress to whole percents, both ways', () => {
        const series = seriesOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        expect(series.datasets[0].data).toEqual([62, 92])
    })

    it('reads a goal with nothing recorded as not started, rather than as blank', () => {
        // Chart.js draws no bar at all for null, which reads as a goal that is
        // not there rather than one at zero.
        const series = seriesOf(
            mount(ActiveGoalsChart, {
                props: {
                    data: [
                        { title: 'Neuf', progress_pct: null },
                        { title: 'Jamais ouvert', progress_pct: undefined },
                    ],
                },
            }),
        )

        expect(series.datasets[0].data).toEqual([0, 0])
    })

    it('names the figure as a percentage in the tooltip, on the horizontal axis', () => {
        const options = optionsOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        // indexAxis 'y' puts the value on x: reading parsed.y here would print
        // the row number instead of the progress.
        expect(options.plugins.tooltip.callbacks.label({ parsed: { x: 62, y: 0 } })).toBe('62% complété')
        expect(options.indexAxis).toBe('y')
    })
})

describe('ActiveGoalsChart scale', () => {
    /**
     * A goal bar only means something against the whole 100 %. Without the fixed
     * maximum Chart.js rescales to the best goal, and the one that is furthest
     * along looks finished whatever it is really at.
     */
    it('runs from an untouched goal to a finished one, whatever the best goal is at', () => {
        const options = optionsOf(mount(ActiveGoalsChart, { props: { data: [{ title: 'À peine', progress_pct: 4 }] } }))

        expect(options.scales.x.min).toBe(0)
        expect(options.scales.x.max).toBe(100)
    })

    it('marks the axis in percent', () => {
        const options = optionsOf(mount(ActiveGoalsChart, { props: { data: goals } }))

        expect(options.scales.x.ticks.callback(75)).toBe('75%')
        expect(options.scales.x.ticks.callback(0)).toBe('0%')
    })
})
