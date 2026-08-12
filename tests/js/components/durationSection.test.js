import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

/*
 * The chart is a defineAsyncComponent behind a dynamic import. Imported here
 * first, the section's own import resolves out of the module cache inside the
 * tick flushPromises awaits — otherwise it is still a comment node and every
 * assertion below, including the negative ones, would pass for the wrong reason.
 */
await import('@/Components/Stats/DurationDistributionChart.vue')

const DurationSection = (await import('@/Components/Dashboard/DurationSection.vue')).default

/** The four buckets StatsService fills, in the order the dashboard sends them. */
const buckets = (counts) =>
    ['< 30 min', '30-60 min', '60-90 min', '90+ min'].map((label, index) => ({ label, count: counts[index] }))

const mountSection = async (durationDistribution) => {
    const wrapper = mount(DurationSection, { props: { durationDistribution } })

    await flushPromises()

    return wrapper
}

const chartOf = (wrapper) => wrapper.findComponent({ name: 'DurationDistributionChart' })

describe('DurationSection', () => {
    it('hands the buckets to the chart untouched when at least one session is counted', async () => {
        const distribution = buckets([0, 3, 1, 0])

        const wrapper = await mountSection(distribution)

        expect(chartOf(wrapper).exists()).toBe(true)
        expect(chartOf(wrapper).props('data')).toEqual(distribution)
        expect(wrapper.text()).not.toContain('Pas assez de données')
    })

    it('announces the missing data rather than drawing four empty slices', async () => {
        // A user with no session in the window still gets the four buckets, all
        // at zero. Length alone cannot tell that apart from real data.
        const wrapper = await mountSection(buckets([0, 0, 0, 0]))

        expect(chartOf(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données (90j)')
    })

    it('holds the empty state when the dashboard sends nothing at all', async () => {
        const wrapper = await mountSection([])

        expect(chartOf(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données (90j)')
    })
})
