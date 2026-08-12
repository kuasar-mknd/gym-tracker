import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the series
 * it sorts and the tooltip it writes — the numbers a lifter loads a bar from.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const OneRepMaxPercentagesChart = (await import('@/Components/Stats/OneRepMaxPercentagesChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('options')
const labelFor = (wrapper, context) => optionsOf(wrapper).plugins.tooltip.callbacks.label(context)

/** Deliberately unsorted: the table is built keyed by percentage, not ordered. */
const percentages = [
    { percent: 100, value: 120, reps: 1 },
    { percent: 70, value: 84, reps: 12 },
    { percent: 90, value: 108, reps: 4 },
]

describe('OneRepMaxPercentagesChart series', () => {
    it('climbs from the lightest percentage to the heaviest', () => {
        const series = seriesOf(mount(OneRepMaxPercentagesChart, { props: { data: percentages } }))

        expect(series.labels).toEqual(['70%', '90%', '100%'])
    })

    it('keeps every weight with its own percentage while sorting', () => {
        // The regression this guards: sorting the labels and mapping the values
        // off the untouched array, which would show 70 % of the max at 120 kg.
        const series = seriesOf(mount(OneRepMaxPercentagesChart, { props: { data: percentages } }))

        expect(series.datasets[0].data).toEqual([84, 108, 120])
    })

    it('leaves the array it was given in the order the page also reads it in', () => {
        const data = [...percentages]

        mount(OneRepMaxPercentagesChart, { props: { data } })

        expect(data.map((d) => d.percent)).toEqual([100, 70, 90])
    })

    it('holds no bar when there is no estimate yet', () => {
        const series = seriesOf(mount(OneRepMaxPercentagesChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('recomputes when another exercise is selected under it', async () => {
        const wrapper = mount(OneRepMaxPercentagesChart, { props: { data: percentages } })

        await wrapper.setProps({ data: [{ percent: 80, value: 60, reps: 8 }] })

        expect(seriesOf(wrapper).labels).toEqual(['80%'])
        expect(seriesOf(wrapper).datasets[0].data).toEqual([60])
    })
})

describe('OneRepMaxPercentagesChart tooltip', () => {
    it('gives the weight one decimal and the reps that go with it', () => {
        const wrapper = mount(OneRepMaxPercentagesChart, { props: { data: percentages } })

        expect(labelFor(wrapper, { label: '70%', parsed: { y: 84 } })).toBe('84.0 kg (12 reps)')
        expect(labelFor(wrapper, { label: '90%', parsed: { y: 107.46 } })).toBe('107.5 kg (4 reps)')
    })

    it('says nothing about reps when the table has none to give', () => {
        const wrapper = mount(OneRepMaxPercentagesChart, { props: { data: [{ percent: 100, value: 120, reps: '-' }] } })

        expect(labelFor(wrapper, { label: '100%', parsed: { y: 120 } })).toBe('120.0 kg')
    })

    it('still names the weight of a bar whose percentage is not in the table', () => {
        const wrapper = mount(OneRepMaxPercentagesChart, { props: { data: percentages } })

        expect(labelFor(wrapper, { label: '55%', parsed: { y: 66 } })).toBe('66.0 kg')
    })

    it('reads the reps of the exercise now shown, not of the one before it', async () => {
        const wrapper = mount(OneRepMaxPercentagesChart, { props: { data: percentages } })

        await wrapper.setProps({ data: [{ percent: 70, value: 56, reps: 10 }] })

        expect(labelFor(wrapper, { label: '70%', parsed: { y: 56 } })).toBe('56.0 kg (10 reps)')
    })
})
