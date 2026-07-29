import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'

const mountRow = (props = {}) =>
    mount(SwipeableRow, {
        props,
        slots: {
            'action-left': '<button type="button" data-testid="edit">Modifier</button>',
            'action-right': '<button type="button" data-testid="delete">Supprimer</button>',
            default: '<a href="/workouts/1">Séance</a>',
        },
        attachTo: document.body,
    })

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
})
