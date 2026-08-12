import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
}))

import { passesSlot, layoutStub } from './pageStubs'

import AchievementsIndex from '@/Pages/Achievements/Index.vue'

/**
 * The three categories the seeder actually assigns. The page's filter bar has
 * to cover them or a badge becomes unreachable from anything but "Tous".
 */
const badges = [
    {
        id: 1,
        category: 'consistency',
        name: 'Régulier',
        description: 'Sept jours de suite',
        icon: '🔥',
        is_unlocked: true,
        unlocked_at: '2026-05-23T12:00:00.000000Z',
    },
    {
        id: 2,
        category: 'strength',
        name: 'Force brute',
        description: 'Cent kilos au développé',
        icon: '💪',
        is_unlocked: false,
        unlocked_at: null,
    },
    {
        id: 3,
        category: 'volume',
        name: 'Tonnage',
        description: 'Dix tonnes soulevées',
        icon: '🏋️',
        is_unlocked: false,
        unlocked_at: null,
    },
]

const mountPage = (props = {}) =>
    mount(AchievementsIndex, {
        props: { achievements: badges, summary: { unlocked: 1, total: 3 }, ...props },
        global: {
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

const filterBy = async (wrapper, label) => {
    const button = wrapper.findAll('button').find((candidate) => candidate.text() === label)

    expect(button, `no "${label}" filter on the page`).toBeTruthy()
    await button.trigger('click')
}

describe('Achievements/Index', () => {
    it('counts the badges won against the badges there are', () => {
        const wrapper = mountPage({ summary: { unlocked: 4, total: 8 } })

        expect(wrapper.text()).toContain('4 / 8')
    })

    it('shows every badge until a category is chosen', () => {
        const wrapper = mountPage()

        expect(wrapper.text()).toContain('Régulier')
        expect(wrapper.text()).toContain('Force brute')
        expect(wrapper.text()).toContain('Tonnage')
    })

    it.each([
        ['Constance', 'Régulier', ['Force brute', 'Tonnage']],
        ['Force', 'Force brute', ['Régulier', 'Tonnage']],
        ['Volume', 'Tonnage', ['Régulier', 'Force brute']],
    ])('narrows to %s', async (label, kept, dropped) => {
        const wrapper = mountPage()

        await filterBy(wrapper, label)

        expect(wrapper.text()).toContain(kept)
        dropped.forEach((name) => expect(wrapper.text()).not.toContain(name))
    })

    it('goes back to the whole list on "Tous"', async () => {
        const wrapper = mountPage()

        await filterBy(wrapper, 'Force')
        await filterBy(wrapper, 'Tous')

        expect(wrapper.text()).toContain('Régulier')
        expect(wrapper.text()).toContain('Tonnage')
    })

    /**
     * An empty grid on a filter looks like a loading page. The copy is what
     * tells the user the category is real and simply has nothing in it yet.
     */
    it('says the category is empty rather than showing a blank grid', async () => {
        const wrapper = mountPage({
            achievements: badges.filter((badge) => badge.category !== 'volume'),
        })

        await filterBy(wrapper, 'Volume')

        expect(wrapper.text()).toContain('Aucun badge dans cette catégorie.')
    })

    it('keeps the empty-state copy away from a category that has badges', async () => {
        const wrapper = mountPage()

        await filterBy(wrapper, 'Volume')

        expect(wrapper.text()).not.toContain('Aucun badge dans cette catégorie.')
    })

    it('dates a won badge the French way round', () => {
        const wrapper = mountPage()

        // 23/05, not 05/23: the day comes first everywhere this app is read.
        expect(wrapper.text()).toContain('Débloqué le 23/05/2026')
    })

    /**
     * `unlocked_at` is null on a badge still to be won; running that through
     * `new Date(null).toLocaleDateString()` prints 01/01/1970 as an unlock date.
     */
    it('puts no unlock date on a badge that is still locked', () => {
        const wrapper = mountPage({ achievements: [badges[1]], summary: { unlocked: 0, total: 1 } })

        expect(wrapper.text()).not.toContain('Débloqué le')
    })

    it('greys a locked badge out and leaves a won one in colour', () => {
        const wrapper = mountPage()

        const cards = wrapper.findAll('.group.relative')

        expect(cards).toHaveLength(3)
        expect(cards[0].html()).not.toContain('grayscale')
        expect(cards[1].html()).toContain('grayscale')
    })
})
