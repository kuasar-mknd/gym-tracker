import { describe, it, expect, beforeAll, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import GoalsSummary from '@/Components/Dashboard/GoalsSummary.vue'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

const mountSummary = (activeGoals) =>
    mount(GoalsSummary, {
        props: { activeGoals },
        global: {
            mocks: { route: globalThis.route },
            stubs: { GlassCard: { template: '<div><slot /></div>' } },
        },
    })

const goals = [
    { id: 1, title: 'Squat 140 kg', progress_pct: 42.4 },
    { id: 2, title: 'Trois séances par semaine', progress_pct: 66.6 },
]

describe('GoalsSummary', () => {
    it('lists one row per goal in progress', () => {
        const wrapper = mountSummary(goals)

        expect(wrapper.findAll('[role="progressbar"]')).toHaveLength(2)
        expect(wrapper.text()).toContain('Squat 140 kg')
        expect(wrapper.text()).toContain('Trois séances par semaine')
    })

    it('stays off the dashboard entirely when nothing is in progress', () => {
        const wrapper = mountSummary([])

        // Not an empty list with a heading above it: the whole block is absent,
        // heading, "Voir tout" link and all.
        expect(wrapper.find('section').exists()).toBe(false)
        expect(wrapper.text()).toBe('')
    })

    it('rounds the figure it prints but not the bar it draws', () => {
        const wrapper = mountSummary([goals[0]])
        const bar = wrapper.get('[role="progressbar"]')

        expect(wrapper.text()).toContain('42%')
        expect(bar.get('div').attributes('style')).toContain('width: 42.4%')
        expect(bar.attributes('aria-valuenow')).toBe('42.4')
    })

    it('rounds up past the halfway mark', () => {
        expect(mountSummary([goals[1]]).text()).toContain('67%')
    })

    it('sends every row, and the "see all" link, to the goals page', () => {
        const wrapper = mountSummary(goals)

        const targets = wrapper.findAll('a').map((link) => link.attributes('href'))

        expect(targets).toHaveLength(3)
        expect(new Set(targets)).toEqual(new Set(['/goals.index']))
    })
})
