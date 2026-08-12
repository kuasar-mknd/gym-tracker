import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the series
 * and the callbacks handed to Chart.js. A point plotted against the wrong label
 * is a chart that lies while rendering perfectly.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const SessionVolumeLineChart = (await import('@/Components/Stats/SessionVolumeLineChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('options')

const sessions = [
    { date: '01/07', volume: 4200 },
    { date: '03/07', volume: 5100 },
    { date: '06/07', volume: 3800 },
]

/**
 * A Chart.js scriptable context whose canvas records the gradients asked of it,
 * so the colour callbacks can be exercised without a real canvas.
 */
const scriptableContext = (chartArea) => {
    const gradients = []

    return {
        gradients,
        context: {
            chart: {
                chartArea,
                ctx: {
                    createLinearGradient: (...coords) => {
                        const stops = []
                        gradients.push({ coords, stops })

                        return { addColorStop: (offset, color) => stops.push([offset, color]) }
                    },
                },
            },
        },
    }
}

const chartArea = { top: 0, bottom: 200, left: 10, right: 310 }

describe('SessionVolumeLineChart series', () => {
    it('plots each session volume against its own date, in the order given', () => {
        const series = seriesOf(mount(SessionVolumeLineChart, { props: { data: sessions } }))

        expect(series.labels).toEqual(['01/07', '03/07', '06/07'])
        expect(series.datasets[0].data).toEqual([4200, 5100, 3800])
    })

    it('draws no point at all when no session has been logged', () => {
        const series = seriesOf(mount(SessionVolumeLineChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('plots a lone session rather than collapsing it', () => {
        const series = seriesOf(mount(SessionVolumeLineChart, { props: { data: [sessions[0]] } }))

        expect(series.labels).toEqual(['01/07'])
        expect(series.datasets[0].data).toEqual([4200])
    })

    it('follows the period when it is changed under it', async () => {
        // An Inertia visit reuses the instance rather than remounting it, so a
        // series pinned to the first render would keep the old period's points.
        const wrapper = mount(SessionVolumeLineChart, { props: { data: sessions } })

        await wrapper.setProps({ data: [{ date: '10/07', volume: 6000 }] })

        expect(seriesOf(wrapper).labels).toEqual(['10/07'])
        expect(seriesOf(wrapper).datasets[0].data).toEqual([6000])
    })
})

describe('SessionVolumeLineChart reading', () => {
    it('names the unit in the tooltip, since the axis is hidden', () => {
        // scales.y.display is false: the tooltip is the only place the reader is
        // told these numbers are kilograms.
        const options = optionsOf(mount(SessionVolumeLineChart, { props: { data: sessions } }))

        expect(options.plugins.tooltip.callbacks.label({ parsed: { y: 5100 } })).toBe('5100 kg')
        expect(options.scales.y.display).toBe(false)
    })

    it('reads the whole session under the cursor, not only the nearest point', () => {
        const options = optionsOf(mount(SessionVolumeLineChart, { props: { data: sessions } }))

        expect(options.interaction).toEqual({ mode: 'index', intersect: false })
    })
})

describe('SessionVolumeLineChart colours', () => {
    const datasetOf = () => seriesOf(mount(SessionVolumeLineChart, { props: { data: sessions } })).datasets[0]

    /**
     * Chart.js calls the colour callbacks once before the layout is measured,
     * when chartArea is still undefined. Building a gradient from it on that
     * first pass throws, and the chart never appears at all.
     */
    it('asks for no colour before the chart area is measured', () => {
        const dataset = datasetOf()
        const { context, gradients } = scriptableContext(undefined)

        expect(dataset.backgroundColor(context)).toBeNull()
        expect(dataset.borderColor(context)).toBeNull()
        expect(gradients).toEqual([])
    })

    it('fades the fill downwards and runs the stroke across', () => {
        const dataset = datasetOf()
        const fill = scriptableContext(chartArea)
        const stroke = scriptableContext(chartArea)

        dataset.backgroundColor(fill.context)
        dataset.borderColor(stroke.context)

        // Vertical: coloured at the top of the plot, transparent at the baseline.
        expect(fill.gradients[0].coords).toEqual([0, 0, 0, 200])
        expect(fill.gradients[0].stops[1][1]).toBe('rgba(255, 0, 128, 0)')
        // Horizontal: violet on the left edge, pink on the right.
        expect(stroke.gradients[0].coords).toEqual([10, 0, 310, 0])
        expect(stroke.gradients[0].stops.map(([, color]) => color)).toEqual(['#8800FF', '#FF0080'])
    })
})
