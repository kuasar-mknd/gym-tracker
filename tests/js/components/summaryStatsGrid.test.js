import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * <Deferred> needs an Inertia page context these tests have no reason to build.
 * The stub keeps the two things that matter: the prop key the component tells
 * it to wait on — naming the wrong key is a real defect, the skeleton would
 * never resolve — and the choice between the fallback and the loaded slot.
 */
const deferred = { resolved: true }

vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: [String, Array] },
        setup(_props, { slots }) {
            return () => (deferred.resolved ? slots.default?.() : slots.fallback?.())
        },
    },
}))

const SummaryStatsGrid = (await import('@/Components/Stats/SummaryStatsGrid.vue')).default

const mountGrid = (props = {}) => mount(SummaryStatsGrid, { props })

/**
 * Finds the card carrying a given heading. The four cards are otherwise
 * identical markup, so the heading is the only honest handle on them.
 */
const card = (wrapper, heading) => wrapper.findAll('.group').find((node) => node.text().includes(heading))

const valueOf = (wrapper, heading) => card(wrapper, heading).find('.text-2xl').text()

const deferredKeys = (wrapper) => wrapper.findAllComponents({ name: 'Deferred' }).map((node) => node.props('data'))

beforeEach(() => {
    deferred.resolved = true
})

describe('SummaryStatsGrid counts', () => {
    it('counts one session per point of the volume trend', () => {
        const wrapper = mountGrid({ volumeTrend: [{}, {}, {}] })

        expect(valueOf(wrapper, 'Séances')).toBe('3')
    })

    it('counts the muscle groups and the exercises from their own arrays', () => {
        const wrapper = mountGrid({
            muscleDistribution: new Array(4).fill({}),
            exercises: new Array(12).fill({}),
        })

        expect(valueOf(wrapper, 'Muscles')).toBe('4')
        expect(valueOf(wrapper, 'Exercices')).toBe('12')
    })

    it('shows zero, not a blank card, before any of the arrays arrive', () => {
        const wrapper = mountGrid()

        expect(valueOf(wrapper, 'Séances')).toBe('0')
        expect(valueOf(wrapper, 'Muscles')).toBe('0')
        expect(valueOf(wrapper, 'Exercices')).toBe('0')
    })
})

describe('SummaryStatsGrid monthly comparison', () => {
    const comparison = (wrapper) => card(wrapper, 'vs Mois -1').find('.text-2xl')

    it('signs a better month and paints it green', () => {
        const value = comparison(mountGrid({ monthlyComparison: { percentage: 12 } }))

        expect(value.text()).toBe('+12%')
        expect(value.classes()).toContain('text-emerald-500')
        expect(value.classes()).not.toContain('text-red-500')
    })

    it('paints a worse month red and does not add a plus in front of the minus', () => {
        const value = comparison(mountGrid({ monthlyComparison: { percentage: -8 } }))

        expect(value.text()).toBe('-8%')
        expect(value.classes()).toContain('text-red-500')
        expect(value.classes()).not.toContain('text-emerald-500')
    })

    it('treats an unchanged month as not-a-drop', () => {
        const value = comparison(mountGrid({ monthlyComparison: { percentage: 0 } }))

        expect(value.text()).toBe('+0%')
        expect(value.classes()).toContain('text-emerald-500')
    })

    it('reads +0% rather than a red NaN when the comparison has not arrived', () => {
        const value = comparison(mountGrid())

        expect(value.text()).toBe('+0%')
        expect(value.classes()).toContain('text-emerald-500')
    })
})

describe('SummaryStatsGrid deferred loading', () => {
    it('waits on the consolidated payload when the page provides one', () => {
        expect(deferredKeys(mountGrid({ deferredData: { anything: true } }))).toEqual([
            'deferredData',
            'deferredData',
            'deferredData',
        ])
    })

    it('falls back to the performance stats key when there is no consolidated payload', () => {
        expect(deferredKeys(mountGrid())).toEqual(['performanceStats', 'performanceStats', 'performanceStats'])
    })

    it('skeletons the three deferred cards, and only those, while the data loads', () => {
        deferred.resolved = false

        const wrapper = mountGrid({ volumeTrend: new Array(3).fill({}), exercises: new Array(2).fill({}) })

        // The exercise count is not deferred — it is already on the page — so it
        // must keep showing its number while the other three wait.
        expect(valueOf(wrapper, 'Exercices')).toBe('2')
        expect(card(wrapper, 'Séances').find('.text-2xl').exists()).toBe(false)
        expect(wrapper.findAll('.glass-skeleton')).toHaveLength(3)
    })
})
