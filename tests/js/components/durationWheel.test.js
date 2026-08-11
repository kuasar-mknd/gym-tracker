import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const triggerHaptic = vi.fn()
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => triggerHaptic(...args) }))

import DurationWheel from '@/Components/Workout/DurationWheel.vue'

/**
 * The sheet is a native <dialog> in real use. The stub has to honour `show`:
 * one that always renders its slot would let every assertion below pass on a
 * sheet that never opened.
 */
const sheetStub = { props: ['show'], template: '<div v-if="show"><slot /></div>' }

const mountWheel = (props = {}) =>
    mount(DurationWheel, {
        props: { modelValue: 30, label: 'Durée, série 1, Planche', dusk: 'duration-input-0-0', ...props },
        global: { stubs: { Modal: sheetStub, GlassButton: { template: '<button><slot /></button>' } } },
    })

const trigger = (wrapper) => wrapper.get('[dusk="duration-input-0-0"]')
const column = (wrapper, key) => wrapper.get(`[dusk="duration-input-0-0-${key}"]`)

const openSheet = async (wrapper) => {
    await trigger(wrapper).trigger('click')
    await wrapper.vm.$nextTick()
}

const press = async (wrapper, key, name) => {
    await column(wrapper, key).trigger('keydown', { key: name })
    await wrapper.vm.$nextTick()
}

beforeEach(() => triggerHaptic.mockReset())

describe('DurationWheel — what the trigger shows', () => {
    it('shows hours, minutes and seconds', () => {
        expect(trigger(mountWheel({ modelValue: 1530 })).text()).toBe('00:25:30')
    })

    it('shows the seconds an iPhone could not', () => {
        expect(trigger(mountWheel({ modelValue: 30 })).text()).toBe('00:00:30')
        expect(trigger(mountWheel({ modelValue: 45 })).text()).toBe('00:00:45')
    })

    /**
     * The wheels stop at 23 hours, and rounding a longer duration down to fit
     * them would report a smaller, plausible number — the same lie the old
     * `new Date(s * 1000).toISOString()` told when it wrapped past midnight.
     */
    it('reports a duration past 24 hours as what it is', () => {
        expect(trigger(mountWheel({ modelValue: 90061 })).text()).toBe('25:01:01')
    })

    it('treats nothing, and nonsense, as no duration', () => {
        for (const value of [null, undefined, NaN, -5]) {
            expect(trigger(mountWheel({ modelValue: value })).text(), String(value)).toBe('00:00:00')
        }
    })

    /** "00:10:00" is not a duration to a screen reader. */
    it('names itself in words', () => {
        expect(trigger(mountWheel({ modelValue: 630 })).attributes('aria-label')).toBe(
            'Durée, série 1, Planche : 10 minutes 30 secondes',
        )
        expect(trigger(mountWheel({ modelValue: 0 })).attributes('aria-label')).toBe(
            'Durée, série 1, Planche : aucune durée',
        )
    })
})

describe('DurationWheel — picking a duration', () => {
    it('opens on the value the set holds', async () => {
        const wrapper = mountWheel({ modelValue: 3661 })

        await openSheet(wrapper)

        expect(column(wrapper, 'hours').attributes('aria-valuenow')).toBe('1')
        expect(column(wrapper, 'minutes').attributes('aria-valuenow')).toBe('1')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('1')
    })

    /** Displayed truthfully above; here it has to come inside the wheels' range. */
    it('brings a duration past 24 hours inside the wheels', async () => {
        const wrapper = mountWheel({ modelValue: 90061 })

        await openSheet(wrapper)

        expect(column(wrapper, 'hours').attributes('aria-valuenow')).toBe('23')
    })

    it('does not open when the session is finished', async () => {
        const wrapper = mountWheel({ disabled: true })

        await openSheet(wrapper)

        expect(wrapper.find('[dusk="duration-input-0-0-hours"]').exists()).toBe(false)
    })

    it('emits the whole duration in seconds, and nothing before that', async () => {
        const wrapper = mountWheel({ modelValue: 30 })

        await openSheet(wrapper)
        await press(wrapper, 'minutes', 'PageDown')
        await press(wrapper, 'seconds', 'Home')

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()

        await wrapper.get('[dusk="duration-input-0-0-confirm"]').trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([[600]])
    })

    it('emits zero, which is a duration someone chose', async () => {
        const wrapper = mountWheel({ modelValue: 30 })

        await openSheet(wrapper)
        await press(wrapper, 'seconds', 'Home')
        await wrapper.get('[dusk="duration-input-0-0-confirm"]').trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([[0]])
    })
})

describe('DurationWheel — the keyboard, which is also how a test drives a wheel', () => {
    it('steps by one, and by ten', async () => {
        const wrapper = mountWheel({ modelValue: 0 })

        await openSheet(wrapper)

        await press(wrapper, 'seconds', 'ArrowDown')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('1')

        await press(wrapper, 'seconds', 'PageDown')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('11')

        await press(wrapper, 'seconds', 'PageUp')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('1')

        await press(wrapper, 'seconds', 'ArrowUp')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('0')
    })

    it('stops at each end rather than wrapping', async () => {
        const wrapper = mountWheel({ modelValue: 0 })

        await openSheet(wrapper)

        await press(wrapper, 'seconds', 'ArrowUp')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('0')

        await press(wrapper, 'seconds', 'End')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('59')

        await press(wrapper, 'seconds', 'ArrowDown')
        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('59')

        await press(wrapper, 'hours', 'End')
        expect(column(wrapper, 'hours').attributes('aria-valuenow')).toBe('23')
    })

    it('announces each column and its range', async () => {
        const wrapper = mountWheel({ modelValue: 0 })

        await openSheet(wrapper)

        for (const [key, label, max] of [
            ['hours', 'Heures', '23'],
            ['minutes', 'Minutes', '59'],
            ['seconds', 'Secondes', '59'],
        ]) {
            const element = column(wrapper, key)

            expect(element.attributes('role')).toBe('spinbutton')
            expect(element.attributes('aria-label')).toBe(label)
            expect(element.attributes('aria-valuemin')).toBe('0')
            expect(element.attributes('aria-valuemax')).toBe(max)
            expect(element.attributes('tabindex')).toBe('0')
        }
    })

    it('leaves a key it does not handle to the browser', async () => {
        const wrapper = mountWheel({ modelValue: 0 })

        await openSheet(wrapper)
        await press(wrapper, 'seconds', 'Tab')

        expect(column(wrapper, 'seconds').attributes('aria-valuenow')).toBe('0')
    })

    /** No-ops on iOS, where WebKit has no Vibration API; Android gets the detent. */
    it('asks for a detent on each step', async () => {
        const wrapper = mountWheel({ modelValue: 0 })

        await openSheet(wrapper)
        await press(wrapper, 'seconds', 'ArrowDown')

        expect(triggerHaptic).toHaveBeenCalledWith('selection')
    })
})
