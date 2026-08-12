import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))

import RestTimer from '@/Components/Workout/RestTimer.vue'

const mountTimer = (props = {}) =>
    mount(RestTimer, {
        props: { duration: 90, autoStart: false, ...props },
        global: { directives: { press: {} }, stubs: { GlassButton: { template: '<button><slot /></button>' } } },
    })

const bar = (wrapper) => wrapper.find('[role="progressbar"]')

/** The countdown the lifter reads, mm:ss. */
const clock = (wrapper) => wrapper.find('[role="timer"]').text()

const button = (wrapper, dusk) => wrapper.find(`[dusk="${dusk}"]`)

const toggleButton = (wrapper) =>
    wrapper.findAll('button').find((b) => ['Pause', 'Démarrer le minuteur'].includes(b.attributes('aria-label')))

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

/**
 * The bar is scaled against the duration it started with, and the +30s button
 * pushes the remaining time past it. The fill overflowed its track, and the
 * progressbar reported an aria-valuenow above its own aria-valuemax — a value a
 * screen reader is entitled to treat as nonsense.
 */
describe('RestTimer progress bar', () => {
    it('reports a full bar at the start', () => {
        const wrapper = mountTimer({ duration: 90 })

        expect(bar(wrapper).attributes('aria-valuenow')).toBe('100')
        expect(bar(wrapper).attributes('aria-valuemax')).toBe('100')
    })

    it('never reports more than its own maximum after time is added', async () => {
        const wrapper = mountTimer({ duration: 90 })

        // Two taps of +30s on a 90 second rest.
        wrapper.vm.addTime(30)
        wrapper.vm.addTime(30)
        await wrapper.vm.$nextTick()

        const value = Number(bar(wrapper).attributes('aria-valuenow'))
        const max = Number(bar(wrapper).attributes('aria-valuemax'))

        expect(value).toBeLessThanOrEqual(max)
        expect(bar(wrapper).find('div').attributes('style')).toContain('width: 100%')
    })

    it('never reports less than nothing', async () => {
        const wrapper = mountTimer({ duration: 90 })

        wrapper.vm.addTime(-200)
        await wrapper.vm.$nextTick()

        expect(Number(bar(wrapper).attributes('aria-valuenow'))).toBeGreaterThanOrEqual(0)
    })

    it('does not divide by a duration of zero', () => {
        const wrapper = mountTimer({ duration: 0 })

        expect(Number(bar(wrapper).attributes('aria-valuenow'))).toBe(0)
    })
})

/**
 * The countdown itself, which nothing covered: the timer is driven by an
 * absolute `endTime` rather than by decrementing a counter, precisely so that a
 * throttled or suspended tab does not lose seconds — and that is the part most
 * easily broken by a "simplification".
 */
describe('RestTimer countdown', () => {
    it('writes the remaining time as m:ss', () => {
        // 65 seconds is a minute and five seconds; the seconds stay padded.
        expect(clock(mountTimer({ duration: 65 }))).toBe('1:05')
        expect(clock(mountTimer({ duration: 90 }))).toBe('1:30')
        expect(clock(mountTimer({ duration: 7 }))).toBe('0:07')
    })

    it('counts down once a second while running', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('1:25')
    })

    it('stays where it is until it is started', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('1:30')
    })

    it('adds thirty seconds to the time left, and keeps them', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        vi.advanceTimersByTime(10000)
        await button(wrapper, 'add-30s').trigger('click')
        expect(clock(wrapper)).toBe('1:50')

        // The next tick recomputes from the deadline: if +30s had not moved it
        // too, the added time would evaporate a second later.
        vi.advanceTimersByTime(1000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('1:49')
    })

    it('recovers the seconds a backgrounded tab did not tick', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        // A suspended tab stops firing the interval while the clock keeps
        // moving: one minute passes with no tick at all.
        vi.setSystemTime(Date.now() + 60000)
        document.dispatchEvent(new Event('visibilitychange'))
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('0:30')
    })

    it('holds the time still while paused, and resumes from there', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        vi.advanceTimersByTime(20000)
        await toggleButton(wrapper).trigger('click')

        vi.advanceTimersByTime(30000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('1:10')

        await toggleButton(wrapper).trigger('click')
        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('1:00')
    })

    it('announces the end when the rest runs out', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('finished')).toHaveLength(1)
        expect(clock(wrapper)).toBe('0:00')
    })

    it('finishes early when the rest is skipped', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        await button(wrapper, 'skip-rest-timer').trigger('click')

        expect(wrapper.emitted('finished')).toHaveLength(1)
        expect(clock(wrapper)).toBe('0:00')
    })

    it('closes without claiming the rest was completed', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        await button(wrapper, 'close-timer').trigger('click')

        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(wrapper.emitted('finished')).toBeUndefined()

        // Closing also stops the countdown; a hidden timer must not fire.
        vi.advanceTimersByTime(120000)
        expect(wrapper.emitted('finished')).toBeUndefined()
    })

    it('picks up a new rest duration while it is idle, but not mid-rest', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        await wrapper.setProps({ duration: 120 })
        expect(clock(wrapper)).toBe('2:00')

        // The next exercise's default must not overwrite a rest already ticking.
        await toggleButton(wrapper).trigger('click')
        vi.advanceTimersByTime(5000)
        await wrapper.setProps({ duration: 60 })
        expect(clock(wrapper)).toBe('1:55')
    })

    it('leaves no interval running once it is unmounted', () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        expect(vi.getTimerCount()).toBe(1)
        wrapper.unmount()

        expect(vi.getTimerCount()).toBe(0)
    })
})
