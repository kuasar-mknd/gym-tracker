import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import StreakCounter from '@/Components/Dashboard/StreakCounter.vue'

const mountCounter = (props = {}) => mount(StreakCounter, { props })

/**
 * The number and its unit, read as one line. Asserting them separately over the
 * whole card would pass on a "12" that landed anywhere and a "jours" that never
 * moved — the pair has to sit together to read as a streak.
 */
const streak = (wrapper) =>
    wrapper
        .get('.items-baseline')
        .findAll('span')
        .map((part) => part.text())

describe('StreakCounter', () => {
    it('reads the streak in days', () => {
        expect(streak(mountCounter({ count: 12 }))).toEqual(['12', 'jours'])
    })

    /**
     * The dashboard renders this card before the streak has been fetched, so
     * the prop arrives absent. `{{ undefined }}` reads as an empty box where a
     * number belongs.
     */
    it('reads zero when the dashboard has not sent a streak', () => {
        expect(streak(mountCounter())).toEqual(['0', 'jours'])
    })

    /**
     * A broken streak and a live one are told apart by the flame alone: lit and
     * pulsing while the streak runs, greyed out once it is gone. Rendering the
     * lit flame at zero tells the user they still have a streak they no longer
     * have.
     */
    it('greys the flame out once the streak is broken', () => {
        const wrapper = mountCounter({ count: 0 })

        expect(wrapper.find('.grayscale').exists()).toBe(true)
        expect(wrapper.find('.animate-pulse-slow').exists()).toBe(false)
    })

    it('lights the flame while the streak is running', () => {
        const wrapper = mountCounter({ count: 1 })

        expect(wrapper.find('.animate-pulse-slow').exists()).toBe(true)
        expect(wrapper.find('.grayscale').exists()).toBe(false)
    })
})
