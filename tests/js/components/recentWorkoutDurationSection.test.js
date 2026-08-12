import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

/*
 * The chart is a defineAsyncComponent behind a dynamic import. Loaded here
 * first, the section's own import resolves from the module cache within the
 * tick flushPromises awaits — otherwise the slot is still a comment node and
 * every assertion below would be about nothing at all.
 */
await import('@/Components/Stats/DashboardDurationChart.vue')

const RecentWorkoutDurationSection = (await import('@/Components/Dashboard/RecentWorkoutDurationSection.vue')).default

const mountSection = async (recentWorkouts) => {
    const wrapper = mount(RecentWorkoutDurationSection, { props: { recentWorkouts } })

    await flushPromises()

    return wrapper
}

const chart = (wrapper) => wrapper.findComponent({ name: 'DashboardDurationChart' })

const finished = { id: 1, started_at: '2026-07-28T18:00:00Z', ended_at: '2026-07-28T19:05:00Z' }
const running = { id: 2, started_at: '2026-07-29T18:00:00Z', ended_at: null }

describe('RecentWorkoutDurationSection', () => {
    it('draws the durations once at least one session has been closed', async () => {
        const wrapper = await mountSection([finished, running])

        expect(chart(wrapper).exists()).toBe(true)
        expect(chart(wrapper).props('data')).toEqual([finished, running])
        expect(wrapper.text()).not.toContain('Pas assez de données de durée')
    })

    it('says there is nothing to draw while every session is still open', async () => {
        const wrapper = await mountSection([running])

        expect(chart(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données de durée')
    })

    it('says the same for a user who has never trained', async () => {
        const wrapper = await mountSection([])

        expect(chart(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données de durée')
    })
})
