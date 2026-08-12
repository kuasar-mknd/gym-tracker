import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const triggerHaptic = vi.fn()

vi.mock('@/composables/useHaptics', () => ({
    triggerHaptic: (...args) => triggerHaptic(...args),
}))

import { vPress } from '@/directives/vPress'

/**
 * The directive is the app's only press affordance on touch: a finger covers
 * the button it is pressing, so the scale-down is the sole confirmation the tap
 * registered. Everything asserted here is that feedback — that it appears on
 * press, that it goes away again on every way a press can end, and that a
 * button which cannot be pressed never pretends otherwise.
 */
const mountPressable = ({ options = null, disabled = false, klass = '', style = '' } = {}) =>
    mount(
        {
            directives: { press: vPress },
            props: {
                options: { type: Object, default: null },
                disabled: { type: Boolean, default: false },
                klass: { type: String, default: '' },
                style: { type: String, default: '' },
            },
            template: '<button v-press="options" :disabled="disabled" :class="klass" :style="style">Go</button>',
        },
        { props: { options, disabled, klass, style }, attachTo: document.body },
    )

beforeEach(() => {
    triggerHaptic.mockReset()
})

describe('v-press', () => {
    it('scales the element down while the mouse is held on it', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('mousedown')

        expect(wrapper.element.style.transform).toBe('scale(0.95)')

        wrapper.unmount()
    })

    it('honours a scale given at the call site', async () => {
        const wrapper = mountPressable({ options: { scale: 0.8 } })

        await wrapper.trigger('mousedown')

        expect(wrapper.element.style.transform).toBe('scale(0.8)')

        wrapper.unmount()
    })

    it('releases the element when the mouse comes back up', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('mousedown')
        await wrapper.trigger('mouseup')

        expect(wrapper.element.style.transform).toBe('')

        wrapper.unmount()
    })

    /**
     * Dragging off the control cancels the press, and no mouseup ever arrives
     * on the button. Without this the element stays visually held down for the
     * rest of its life.
     */
    it('releases the element when the pointer leaves it mid-press', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('mousedown')
        await wrapper.trigger('mouseleave')

        expect(wrapper.element.style.transform).toBe('')

        wrapper.unmount()
    })

    it('scales on touchstart and releases on touchend', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('touchstart')
        expect(wrapper.element.style.transform).toBe('scale(0.95)')

        await wrapper.trigger('touchend')
        expect(wrapper.element.style.transform).toBe('')

        wrapper.unmount()
    })

    /**
     * A cancelled touch — the browser deciding the gesture was a scroll — fires
     * touchcancel and nothing else.
     */
    it('releases the element on touchcancel', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('touchstart')
        await wrapper.trigger('touchcancel')

        expect(wrapper.element.style.transform).toBe('')

        wrapper.unmount()
    })

    it('gives no press feedback on a disabled button', () => {
        const wrapper = mountPressable({ disabled: true })

        // Dispatched rather than triggered: Vue Test Utils declines to trigger
        // on a disabled element, so a trigger here would pass whatever the
        // directive does.
        wrapper.element.dispatchEvent(new MouseEvent('mousedown'))

        expect(wrapper.element.style.transform).toBe('')
        expect(triggerHaptic).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    /**
     * Non-button elements cannot carry the disabled attribute, so the app marks
     * them with the class instead.
     */
    it('gives no press feedback on an element marked disabled by class', async () => {
        const wrapper = mountPressable({ klass: 'disabled' })

        await wrapper.trigger('mousedown')

        expect(wrapper.element.style.transform).toBe('')
        expect(triggerHaptic).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    it('vibrates a tap by default', async () => {
        const wrapper = mountPressable()

        await wrapper.trigger('mousedown')

        expect(triggerHaptic).toHaveBeenCalledWith('tap')

        wrapper.unmount()
    })

    it('vibrates the named pattern when the call site asks for one', async () => {
        const wrapper = mountPressable({ options: { haptic: 'success' } })

        await wrapper.trigger('mousedown')

        expect(triggerHaptic).toHaveBeenCalledWith('success')

        wrapper.unmount()
    })

    it('stays silent when haptics are turned off', async () => {
        const wrapper = mountPressable({ options: { haptic: false } })

        await wrapper.trigger('mousedown')

        expect(triggerHaptic).not.toHaveBeenCalled()
        expect(wrapper.element.style.transform).toBe('scale(0.95)')

        wrapper.unmount()
    })

    /**
     * Six listeners per pressable element, and this app puts the directive on
     * every button of a list that re-renders on each sync. Leaving them
     * attached keeps the detached node — and its handler closure — alive.
     */
    it('stops responding once unmounted', () => {
        const wrapper = mountPressable()
        const element = wrapper.element

        wrapper.unmount()

        // A press-start listener left behind would still scale and vibrate.
        for (const type of ['mousedown', 'touchstart']) {
            element.dispatchEvent(new Event(type))

            expect(element.style.transform, type).toBe('')
            expect(triggerHaptic, type).not.toHaveBeenCalled()
        }

        // A press-end listener left behind would still wipe a transform the
        // element's next owner had set — asserting '' here would prove nothing,
        // since a detached element has no transform to begin with.
        element.style.transform = 'scale(0.5)'

        for (const type of ['mouseup', 'mouseleave', 'touchend', 'touchcancel']) {
            element.dispatchEvent(new Event(type))

            expect(element.style.transform, type).toBe('scale(0.5)')
        }
    })

    it('gives the element back the transition it arrived with', () => {
        const wrapper = mountPressable({ style: 'transition: opacity 200ms ease' })
        const element = wrapper.element

        // The directive appends its own transform transition to whatever was there.
        expect(element.style.transition).toContain('opacity 200ms ease')
        expect(element.style.transition).toContain('transform 100ms ease-out')

        wrapper.unmount()

        expect(element.style.transition).toBe('opacity 200ms ease')
        expect(element.style.willChange).toBe('')
    })
})
