import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the series
 * and the unit handed to Chart.js.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const WorkoutDurationChart = (await import('@/Components/Stats/WorkoutDurationChart.vue')).default

const seriesOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('data')
const optionsOf = (wrapper) => wrapper.findComponent({ name: 'Line' }).props('options')

const sessions = [
    { date: '01/07', duration: 62 },
    { date: '03/07', duration: 48 },
    { date: '06/07', duration: 75 },
]

describe('WorkoutDurationChart series', () => {
    it('plots each duration against its own date, in the order given', () => {
        const series = seriesOf(mount(WorkoutDurationChart, { props: { data: sessions } }))

        expect(series.labels).toEqual(['01/07', '03/07', '06/07'])
        expect(series.datasets[0].data).toEqual([62, 48, 75])
        expect(series.datasets[0].label).toBe('Durée (min)')
    })

    it('plots a session that was cut short rather than dropping it', () => {
        const series = seriesOf(mount(WorkoutDurationChart, { props: { data: [{ date: '01/07', duration: 0 }] } }))

        expect(series.datasets[0].data).toEqual([0])
    })

    it('holds nothing when no session has been timed', () => {
        const series = seriesOf(mount(WorkoutDurationChart, { props: { data: [] } }))

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('follows the period when it is changed under it', async () => {
        const wrapper = mount(WorkoutDurationChart, { props: { data: sessions } })

        await wrapper.setProps({ data: [{ date: '10/07', duration: 55 }] })

        expect(seriesOf(wrapper).labels).toEqual(['10/07'])
        expect(seriesOf(wrapper).datasets[0].data).toEqual([55])
    })
})

describe('WorkoutDurationChart reading', () => {
    it('reads the value in minutes, which nothing else on the card says', () => {
        const options = optionsOf(mount(WorkoutDurationChart, { props: { data: sessions } }))

        expect(options.plugins.tooltip.callbacks.label({ parsed: { y: 62 } })).toBe('62 min')
    })

    it('reads the whole session under the cursor, not only the nearest point', () => {
        const options = optionsOf(mount(WorkoutDurationChart, { props: { data: sessions } }))

        expect(options.interaction).toEqual({ mode: 'index', intersect: false })
    })
})
