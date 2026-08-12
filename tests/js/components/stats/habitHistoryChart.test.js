import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the count
 * plotted per day and the axis it is read against.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const HabitHistoryChart = (await import('@/Components/Stats/HabitHistoryChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Bar' }).props('options')

const days = [
    { date: '01/07', count: 3 },
    { date: '02/07', count: 0 },
    { date: '03/07', count: 5 },
]

describe('HabitHistoryChart series', () => {
    it('plots the habits closed each day against that day, in the order given', () => {
        const series = seriesOf(mount(HabitHistoryChart, { props: { data: days } }))

        expect(series.labels).toEqual(['01/07', '02/07', '03/07'])
        expect(series.datasets[0].data).toEqual([3, 0, 5])
    })

    it('keeps the day nothing was closed on in the run', () => {
        // A missed day is the point of a streak chart: dropped, the history
        // reads as unbroken.
        const series = seriesOf(mount(HabitHistoryChart, { props: { data: days } }))

        expect(series.labels).toHaveLength(3)
        expect(series.datasets[0].data[1]).toBe(0)
    })

    it('holds nothing when no habit has ever been closed', () => {
        const series = seriesOf(mount(HabitHistoryChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the history when a day is closed under it', async () => {
        const wrapper = mount(HabitHistoryChart, { props: { data: days } })

        await wrapper.setProps({ data: [...days, { date: '04/07', count: 2 }] })

        expect(seriesOf(wrapper).labels).toHaveLength(4)
        expect(seriesOf(wrapper).datasets[0].data).toEqual([3, 0, 5, 2])
    })
})

describe('HabitHistoryChart axis', () => {
    it('counts habits in the tooltip', () => {
        const options = optionsOf(mount(HabitHistoryChart, { props: { data: days } }))

        expect(options.plugins.tooltip.callbacks.label({ parsed: { y: 3 } })).toBe('3 habitudes')
    })

    /**
     * Habits are closed one at a time: an axis free to pick its own step offers
     * half a habit, and one free to pick its own floor cuts the missed days out
     * of the comparison entirely.
     */
    it('steps one habit at a time, from zero', () => {
        const options = optionsOf(mount(HabitHistoryChart, { props: { data: days } }))

        expect(options.scales.y.ticks.stepSize).toBe(1)
        expect(options.scales.y.beginAtZero).toBe(true)
    })

    it('keeps the dates flat and thinned rather than turned on their side', () => {
        const options = optionsOf(mount(HabitHistoryChart, { props: { data: days } }))

        expect(options.scales.x.ticks.maxRotation).toBe(0)
        expect(options.scales.x.ticks.autoSkip).toBe(true)
        expect(options.scales.x.ticks.maxTicksLimit).toBe(7)
    })
})
