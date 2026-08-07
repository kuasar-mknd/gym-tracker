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
