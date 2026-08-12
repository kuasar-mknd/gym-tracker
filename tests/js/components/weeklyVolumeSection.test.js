import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

// See durationSection.test.js: the async chart has to be in the module cache
// before the section is imported, or it never renders and the assertions lie.
await import('@/Components/Stats/WeeklyVolumeChart.vue')

const WeeklyVolumeSection = (await import('@/Components/Dashboard/WeeklyVolumeSection.vue')).default

const trend = [
    { week: '2026-W30', volume: 18000 },
    { week: '2026-W31', volume: 21500 },
]

const mountSection = async (props) => {
    const wrapper = mount(WeeklyVolumeSection, {
        props: { weeklyVolumeStats: {}, weeklyVolumeTrend: trend, ...props },
    })

    await flushPromises()

    return wrapper
}

const chartOf = (wrapper) => wrapper.findComponent({ name: 'WeeklyVolumeChart' })

/** The big number, read the way the user reads it. */
const volumeText = (wrapper) => wrapper.get('p.text-4xl').text()

/** The "+12% vs sem. passée" line, absent altogether when the weeks tie. */
const comparisonOf = (wrapper) => wrapper.get('p.justify-end')

describe('WeeklyVolumeSection', () => {
    it('groups the thousands in the week volume instead of printing a raw integer', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 0 } })

        expect(volumeText(wrapper)).toBe((21500).toLocaleString())
        expect(volumeText(wrapper)).not.toBe('21500')
    })

    it('falls back to zero when the week has no volume yet', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: {} })

        expect(volumeText(wrapper)).toBe('0')
    })

    it('reads a gain as a rise, signed and pointing up', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 12 } })

        const comparison = comparisonOf(wrapper)

        expect(comparison.text()).toContain('trending_up')
        expect(comparison.text()).toContain('+12% vs sem. passée')
        expect(comparison.classes()).toContain('text-emerald-600')
    })

    it('reads a drop as a fall, unsigned by us and pointing down', async () => {
        // The minus already comes with the number; prefixing a '+' here would
        // print '+-8%'.
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 9000, percentage: -8 } })

        const comparison = comparisonOf(wrapper)

        expect(comparison.text()).toContain('trending_down')
        expect(comparison.text()).toContain('-8% vs sem. passée')
        expect(comparison.text()).not.toContain('+')
        expect(comparison.classes()).toContain('text-red-500')
    })

    it('says nothing when the two weeks match exactly', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 0 } })

        expect(wrapper.text()).not.toContain('vs sem. passée')
    })

    it('passes the trend on to the chart', async () => {
        const wrapper = await mountSection({ weeklyVolumeTrend: trend })

        expect(chartOf(wrapper).exists()).toBe(true)
        expect(chartOf(wrapper).props('data')).toEqual(trend)
    })

    it('announces the empty week rather than an axis with no points', async () => {
        const wrapper = await mountSection({ weeklyVolumeTrend: [] })

        expect(chartOf(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas de données cette semaine')
    })
})
