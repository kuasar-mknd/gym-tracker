import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ActiveWorkoutBanner from '@/Components/Dashboard/ActiveWorkoutBanner.vue'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${params.workout}`
})

const NOW = new Date('2026-08-12T18:00:00.000Z')

/** @param {number} seconds How long ago the session was started. */
const startedSecondsAgo = (seconds) => new Date(NOW.getTime() - seconds * 1000).toISOString()

const mountBanner = (workout) =>
    mount(ActiveWorkoutBanner, {
        props: { workout: { id: 12, name: 'Push A', ...workout } },
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } },
        },
    })

/**
 * The first count is computed in `onMounted`, so the elapsed time is still
 * empty on the paint `mount()` returns. One tick renders it.
 *
 * @param {number} seconds How long ago the session was started.
 */
const mountRunningFor = async (seconds) => {
    const wrapper = mountBanner({ started_at: startedSecondsAgo(seconds) })
    await wrapper.vm.$nextTick()

    return wrapper
}

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
})

afterEach(() => vi.useRealTimers())

/**
 * The banner is the only place the elapsed time of a running session is shown,
 * and it is computed here rather than sent by the server. Nothing covered it.
 */
describe('ActiveWorkoutBanner elapsed time', () => {
    it('counts minutes and seconds under the hour, both padded', async () => {
        // 7 seconds, not 10: an unpadded value would read "45m 7s".
        const wrapper = await mountRunningFor(45 * 60 + 7)

        expect(wrapper.text()).toContain('45m 07s')
    })

    it('switches to hours and minutes once the session passes an hour', async () => {
        const wrapper = await mountRunningFor(2 * 3600 + 5 * 60 + 30)

        expect(wrapper.text()).toContain('2h 05m')
        expect(wrapper.text()).not.toContain('125m')
    })

    it('keeps counting while the banner is on screen', async () => {
        const wrapper = await mountRunningFor(58)
        expect(wrapper.text()).toContain('0m 58s')

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('1m 01s')
    })

    it('stops counting once it leaves the screen', () => {
        const wrapper = mountBanner({ started_at: startedSecondsAgo(30) })

        expect(vi.getTimerCount()).toBe(1)
        wrapper.unmount()

        expect(vi.getTimerCount()).toBe(0)
    })
})

describe('ActiveWorkoutBanner content', () => {
    it('links to the session it announces', () => {
        const wrapper = mountBanner({ id: 42, started_at: startedSecondsAgo(60) })

        expect(wrapper.find('a').attributes('href')).toBe('/workouts.show/42')
    })

    /*
     * Read off the heading rather than off the whole banner: the kicker above
     * it already says "Séance active", so a `toContain('Séance')` on the text
     * of the card passes whether or not the fallback exists.
     */
    it('names the session, and falls back to a generic name when it has none', () => {
        expect(
            mountBanner({ name: 'Push A', started_at: startedSecondsAgo(60) })
                .get('h3')
                .text(),
        ).toBe('Push A')
        expect(
            mountBanner({ name: null, started_at: startedSecondsAgo(60) })
                .get('h3')
                .text(),
        ).toBe('Séance')
    })

    it('counts the exercises logged so far', () => {
        const wrapper = mountBanner({ started_at: startedSecondsAgo(60), workout_lines_count: 5 })

        expect(wrapper.text()).toContain('5 exos')
    })

    it('says nothing about exercises before the first one is logged', () => {
        const wrapper = mountBanner({ started_at: startedSecondsAgo(60), workout_lines_count: 0 })

        expect(wrapper.text()).not.toContain('exos')
    })
})
