import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the rep
 * count plotted per session and the axis it is read against.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const MaxRepsChart = (await import('@/Components/Stats/MaxRepsChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('options')

const sets = [
    { date: '01/07', reps: 8 },
    { date: '08/07', reps: 11 },
    { date: '15/07', reps: 10 },
]

describe('MaxRepsChart series', () => {
    it('plots the best rep count of each session against its own date', () => {
        const series = seriesOf(mount(MaxRepsChart, { props: { data: sets } }))

        expect(series.labels).toEqual(['01/07', '08/07', '15/07'])
        expect(series.datasets[0].data).toEqual([8, 11, 10])
        expect(series.datasets[0].label).toBe('Max Reps')
    })

    it('holds nothing when the exercise has never been logged', () => {
        const series = seriesOf(mount(MaxRepsChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the exercise when another one is selected under it', async () => {
        const wrapper = mount(MaxRepsChart, { props: { data: sets } })

        await wrapper.setProps({ data: [{ date: '20/07', reps: 5 }] })

        expect(seriesOf(wrapper).labels).toEqual(['20/07'])
        expect(seriesOf(wrapper).datasets[0].data).toEqual([5])
    })
})

describe('MaxRepsChart axis', () => {
    it('never offers half a repetition', () => {
        const options = optionsOf(mount(MaxRepsChart, { props: { data: sets } }))

        expect(options.scales.y.ticks.precision).toBe(0)
    })
})

describe('MaxRepsChart fill', () => {
    /**
     * Chart.js calls the colour callback once before the layout is measured,
     * when chartArea is still undefined. Building a gradient from it on that
     * first pass throws, and the chart never appears at all.
     */
    it('asks for no fill before the chart area is measured', () => {
        const dataset = seriesOf(mount(MaxRepsChart, { props: { data: sets } })).datasets[0]
        const createLinearGradient = vi.fn()

        expect(dataset.backgroundColor({ chart: { chartArea: undefined, ctx: { createLinearGradient } } })).toBeNull()
        expect(createLinearGradient).not.toHaveBeenCalled()
    })

    it('fades the fill down from the curve to the baseline once it can', () => {
        const dataset = seriesOf(mount(MaxRepsChart, { props: { data: sets } })).datasets[0]
        const stops = []
        const createLinearGradient = vi.fn(() => ({ addColorStop: (offset, color) => stops.push([offset, color]) }))

        dataset.backgroundColor({
            chart: { chartArea: { top: 0, bottom: 200, left: 10, right: 310 }, ctx: { createLinearGradient } },
        })

        expect(createLinearGradient).toHaveBeenCalledWith(0, 0, 0, 200)
        expect(stops[1][1]).toBe('rgba(139, 92, 246, 0)')
    })
})
