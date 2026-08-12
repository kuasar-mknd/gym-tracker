import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('../chartRecorder.js')).chartRecorders())

/*
 * Both sections hold their chart behind a defineAsyncComponent, so the dynamic
 * import has to be in the module cache before the parents are imported —
 * otherwise the slot is still a comment node after flushPromises and every
 * assertion below would be about a chart that never rendered.
 */
await import('@/Components/Stats/TimeOfDayChart.vue')
await import('@/Components/Stats/RecentWorkoutsExercisesChart.vue')

const TimeOfDaySection = (await import('@/Components/Dashboard/TimeOfDaySection.vue')).default
const RecentExercisesSection = (await import('@/Components/Dashboard/RecentExercisesSection.vue')).default

const mountSection = async (component, props) => {
    const wrapper = mount(component, { props })

    await flushPromises()

    return wrapper
}

const distribution = [
    { label: 'Matin', count: 4 },
    { label: 'Après-midi', count: 0 },
    { label: 'Soir', count: 7 },
    { label: 'Nuit', count: 0 },
]

const workouts = [
    { started_at: '2026-07-31T18:00:00.000Z', workout_lines_count: 5 },
    { started_at: '2026-07-29T18:00:00.000Z', workout_lines_count: 0 },
]

describe('TimeOfDaySection', () => {
    it('hands the whole distribution to the chart, zero buckets included', async () => {
        const wrapper = await mountSection(TimeOfDaySection, { timeOfDayDistribution: distribution })
        const chart = wrapper.findComponent({ name: 'TimeOfDayChart' })

        expect(chart.exists()).toBe(true)
        // Dropping the empty buckets here would draw a doughnut whose slices no
        // longer line up with the four parts of the day it colours them by.
        expect(chart.props('data')).toEqual(distribution)
        expect(wrapper.text()).not.toContain('Pas assez de données')
    })

    /**
     * The page sends the four buckets whether or not anything landed in them,
     * so an all-zero distribution is the real "no data" case — and a doughnut
     * of four zero slices draws nothing at all.
     */
    it('says there is not enough data when every bucket is empty', async () => {
        const wrapper = await mountSection(TimeOfDaySection, {
            timeOfDayDistribution: distribution.map((bucket) => ({ ...bucket, count: 0 })),
        })

        expect(wrapper.findComponent({ name: 'TimeOfDayChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données (90j)')
    })

    it('holds the empty state when the page sends nothing', async () => {
        const wrapper = await mountSection(TimeOfDaySection, { timeOfDayDistribution: [] })

        expect(wrapper.findComponent({ name: 'TimeOfDayChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données (90j)')
    })
})

describe('RecentExercisesSection', () => {
    it('hands the whole series to the chart, empty sessions included', async () => {
        const wrapper = await mountSection(RecentExercisesSection, { recentWorkouts: workouts })
        const chart = wrapper.findComponent({ name: 'RecentWorkoutsExercisesChart' })

        expect(chart.exists()).toBe(true)
        expect(chart.props('data')).toEqual(workouts)
        expect(wrapper.text()).not.toContain("Pas assez de données d'exercices")
    })

    /** Sessions exist, but none of them logged an exercise: nothing to plot. */
    it('says there is not enough data when no session logged an exercise', async () => {
        const wrapper = await mountSection(RecentExercisesSection, {
            recentWorkouts: workouts.map((workout) => ({ ...workout, workout_lines_count: 0 })),
        })

        expect(wrapper.findComponent({ name: 'RecentWorkoutsExercisesChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain("Pas assez de données d'exercices")
    })

    it('holds the empty state when the page sends nothing', async () => {
        const wrapper = await mountSection(RecentExercisesSection, { recentWorkouts: [] })

        expect(wrapper.findComponent({ name: 'RecentWorkoutsExercisesChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain("Pas assez de données d'exercices")
    })
})
