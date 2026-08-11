import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'

const mountRow = (props = {}, slots = {}) =>
    mount(SwipeableRow, {
        props,
        slots: {
            'action-left': '<button type="button" data-testid="edit">Modifier</button>',
            'action-right': '<button type="button" data-testid="delete">Supprimer</button>',
            default: '<a href="/workouts/1">Séance</a>',
            ...slots,
        },
        attachTo: document.body,
    })

/** A set row, and a session card: their only action is the delete on the right. */
const mountDeleteOnlyRow = () =>
    mount(SwipeableRow, {
        slots: {
            'action-right': '<button type="button" data-testid="delete">Supprimer</button>',
            default: '<a href="/workouts/1">Séance</a>',
        },
        attachTo: document.body,
    })

const drag = async (wrapper, from, to) => {
    const content = wrapper.find('.relative.z-10')

    await content.trigger('touchstart', { touches: [{ clientX: from, clientY: 10 }] })
    await content.trigger('touchmove', { touches: [{ clientX: to, clientY: 10 }] })
}

const sideOpacity = (wrapper, side) =>
    Number.parseFloat(
        wrapper
            .find(`.absolute.inset-y-0.${side}-0`)
            .attributes('style')
            .match(/opacity:\s*([\d.]+)/)[1],
    )

const actionsLayer = (wrapper) => wrapper.find('.absolute.inset-0')

const opacityOf = (wrapper) =>
    Number.parseFloat(
        actionsLayer(wrapper)
            .attributes('style')
            .match(/opacity:\s*([\d.]+)/)[1],
    )

const translateOf = (wrapper) => {
    const style = wrapper.find('.relative.z-10').attributes('style')

    return Number.parseFloat(style.match(/translateX\((-?[\d.]+)px\)/)[1])
}

describe('SwipeableRow', () => {
    /**
     * The actions live behind the row at opacity 0 and only appear once a swipe
     * drags the content aside — but they stay in the tab order the whole time.
     * On Workouts/Index the right-hand action deletes the session and is the
     * only delete affordance on the card, so a keyboard user could focus, and
     * activate, a destructive control that renders nothing on screen.
     */
    it('is fully transparent while closed', () => {
        const wrapper = mountRow()

        expect(opacityOf(wrapper)).toBe(0)
        expect(translateOf(wrapper)).toBe(0)

        wrapper.unmount()
    })

    it('opens the row when a right-hand action takes focus', async () => {
        const wrapper = mountRow()

        await wrapper.find('[data-testid="delete"]').trigger('focusin')

        expect(translateOf(wrapper)).toBe(-80)
        expect(opacityOf(wrapper)).toBe(1)

        wrapper.unmount()
    })

    it('opens the other way for a left-hand action', async () => {
        const wrapper = mountRow({ actionThreshold: 64 })

        await wrapper.find('[data-testid="edit"]').trigger('focusin')

        expect(translateOf(wrapper)).toBe(64)

        wrapper.unmount()
    })

    it('closes again once focus leaves the actions', async () => {
        const wrapper = mountRow()

        await wrapper.find('[data-testid="delete"]').trigger('focusin')
        await wrapper.find('[data-testid="delete"]').trigger('focusout', { relatedTarget: document.body })

        expect(translateOf(wrapper)).toBe(0)
        expect(opacityOf(wrapper)).toBe(0)

        wrapper.unmount()
    })

    it('stays open while focus moves between two actions of the same row', async () => {
        const wrapper = mountRow()

        const edit = wrapper.find('[data-testid="edit"]')
        const remove = wrapper.find('[data-testid="delete"]')

        await edit.trigger('focusin')
        await edit.trigger('focusout', { relatedTarget: remove.element })

        expect(translateOf(wrapper)).toBe(80)

        wrapper.unmount()
    })

    /**
     * ExerciseCard disables the row while its inline edit form is open. Keeping
     * the layer mounted there would put two invisible buttons in front of the
     * form's own fields.
     */
    it('drops the actions entirely when the row is disabled', () => {
        const wrapper = mountRow({ disabled: true })

        expect(wrapper.find('[data-testid="delete"]').exists()).toBe(false)

        wrapper.unmount()
    })

    it('snaps shut when the row becomes disabled mid-swipe', async () => {
        const wrapper = mountRow()

        await wrapper.find('[data-testid="delete"]').trigger('focusin')
        expect(translateOf(wrapper)).toBe(-80)

        await wrapper.setProps({ disabled: true })

        expect(translateOf(wrapper)).toBe(0)

        wrapper.unmount()
    })

    /**
     * An action is exactly the strip the content has uncovered, and never wider.
     *
     * Each action used to be half the row, with the content sitting on top of
     * the other half — and the content is glass, `bg-white/10` over a row at
     * `bg-white/80`. A red delete panel behind that does not stay behind it: on
     * an open row it came through as a pink wash across the values. Fading the
     * layer in with the drag was supposed to hide that, but the fade is complete
     * by 20px and the row opens at 80, so by the time it mattered opacity was 1.
     *
     * Widths are the assertion that can be made without layout: jsdom gives
     * every element a zero rect, so an overlap cannot be measured here. What it
     * can hold is that the action is never asked to be wider than the gap.
     */
    it('sizes an action to the strip the content uncovers, never wider', async () => {
        const wrapper = mountRow()
        const widthOf = (side) =>
            wrapper
                .find(`.absolute.inset-y-0.${side}-0`)
                .attributes('style')
                .match(/width:\s*([\d.]+)px/)[1]

        expect(widthOf('right')).toBe('80')
        expect(widthOf('left')).toBe('80')

        await wrapper.find('[data-testid="delete"]').trigger('focusin')

        expect(Number(widthOf('right'))).toBe(Math.abs(translateOf(wrapper)))

        wrapper.unmount()
    })

    /** An over-drag uncovers more than the snap width; the action has to follow. */
    it('grows with an over-drag rather than leaving a gap', async () => {
        const wrapper = mountRow({ actionThreshold: 64 })
        const content = wrapper.find('.relative.z-10')
        const rightAction = () => wrapper.find('.absolute.inset-y-0.right-0')

        expect(rightAction().attributes('style')).toContain('width: 64px')

        await content.trigger('touchstart', { touches: [{ clientX: 300, clientY: 10 }] })
        await content.trigger('touchmove', { touches: [{ clientX: 100, clientY: 10 }] })

        const dragged = Math.abs(translateOf(wrapper))

        // Past the threshold the drag is damped, so this is well beyond 64 but
        // not the full 200 the finger travelled.
        expect(dragged).toBeGreaterThan(64)
        expect(rightAction().attributes('style')).toContain(`width: ${dragged}px`)

        wrapper.unmount()
    })

    /**
     * Sizing an action to the offset keeps it from bleeding through the content
     * beside it, but not through content that moved *towards* it. A right action
     * is anchored to the right edge, so a swipe to the right slides the glass
     * over it instead of away from it, and the values were washed red by an
     * action that was uncovering nothing.
     */
    it('draws only the action the swipe is uncovering', async () => {
        const wrapper = mountRow()

        await drag(wrapper, 100, 200)

        expect(translateOf(wrapper)).toBeGreaterThan(0)
        expect(sideOpacity(wrapper, 'left')).toBe(1)
        expect(sideOpacity(wrapper, 'right')).toBe(0)

        wrapper.unmount()
    })

    it('draws the other one the other way', async () => {
        const wrapper = mountRow()

        await drag(wrapper, 200, 100)

        expect(translateOf(wrapper)).toBeLessThan(0)
        expect(sideOpacity(wrapper, 'right')).toBe(1)
        expect(sideOpacity(wrapper, 'left')).toBe(0)

        wrapper.unmount()
    })

    /**
     * A set row has only a delete. Pulling it the other way used to slide the
     * whole row off its card to reveal bare background — and, once the action
     * stopped being half the row, the delete panel itself through the glass.
     */
    it('does not follow a drag towards a side with no action', async () => {
        const wrapper = mountDeleteOnlyRow()

        await drag(wrapper, 100, 260)

        expect(translateOf(wrapper)).toBe(0)
        expect(sideOpacity(wrapper, 'right')).toBe(0)

        wrapper.unmount()
    })

    it('still follows a drag towards the side that has one', async () => {
        const wrapper = mountDeleteOnlyRow()

        await drag(wrapper, 260, 100)

        expect(translateOf(wrapper)).toBeLessThan(0)
        expect(sideOpacity(wrapper, 'right')).toBe(1)

        wrapper.unmount()
    })
})
