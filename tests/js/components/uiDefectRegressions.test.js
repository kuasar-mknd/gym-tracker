import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { evenementDeDoigt, imagesALaMain } from '../utils/geste'

/**
 * Two defects found by auditing the interface rather than by writing tests,
 * each pinned here so the fix cannot quietly come undone.
 */
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'

describe('a row that swipes', () => {
    let images

    beforeEach(() => {
        images = imagesALaMain(vi)
    })

    afterEach(() => {
        vi.unstubAllGlobals()
    })

    const mountRow = () =>
        mount(SwipeableRow, {
            props: { hasRightAction: true },
            global: { directives: { press: {} } },
            slots: { default: '<p>Rowing barre</p>', 'action-right': '<button>Supprimer</button>' },
        })

    /** The foreground layer, which is the element that moves. */
    const foreground = (wrapper) => wrapper.get('[data-glissant]')

    const send = (wrapper, type, x, horloge) =>
        foreground(wrapper).element.dispatchEvent(evenementDeDoigt(type, { x, horloge }))

    const swipe = (wrapper, from, to) => {
        send(wrapper, 'pointerdown', from, 0)
        send(wrapper, 'pointermove', from - 11, 16)
        send(wrapper, 'pointermove', to, 40)
    }

    it('goes back to rest when the system takes the gesture away', async () => {
        const wrapper = mountRow()

        swipe(wrapper, 200, 160)
        expect(foreground(wrapper).element.style.transform).not.toBe('translateX(0px)')

        // pointercancel fires when the browser or the OS claims the pointer — a
        // call arriving, the notification shade, the back gesture from the
        // edge. Unhandled, the row stayed frozen mid-swipe with its actions
        // half-revealed.
        send(wrapper, 'pointercancel', 160, 41)
        images.poser()
        await flushPromises()

        expect(foreground(wrapper).element.style.transform).toBe('translateX(0px)')
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('still settles normally when the finger is simply lifted', async () => {
        const wrapper = mountRow()

        send(wrapper, 'pointerdown', 200, 0)
        send(wrapper, 'pointermove', 199, 16)
        send(wrapper, 'pointerup', 199, 40)
        images.poser()
        await flushPromises()

        expect(foreground(wrapper).element.style.transform).toBe('translateX(0px)')
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })
})

describe('a select that hides its label', () => {
    const mountSelect = (props) =>
        mount(GlassSelect, {
            props: { options: [{ value: 'a', label: 'A' }], ...props },
            global: { directives: { press: {} } },
        })

    it('keeps the label for screen readers rather than dropping it', () => {
        const wrapper = mountSelect({ label: 'Type d’exercice', hideLabel: true })
        const label = wrapper.get('label')

        // `hide-label` means visually hidden, not absent. Three selects in this
        // app passed it without a label at all and ended up with no accessible
        // name of any kind.
        expect(label.text()).toBe('Type d’exercice')
        expect(label.classes()).toContain('sr-only')
        expect(label.attributes('for')).toBe(wrapper.get('select').attributes('id'))
    })

    it('points at its error only when there is one', async () => {
        const healthy = mountSelect({ label: 'Catégorie' })

        // Emitted unconditionally, it sent a screen reader to a hidden element
        // holding nothing — on every healthy field on the page.
        expect(healthy.get('select').attributes('aria-describedby')).toBeUndefined()

        const failing = mountSelect({ label: 'Catégorie', error: 'Champ requis' })
        const described = failing.get('select').attributes('aria-describedby')

        expect(described).toBeDefined()
        expect(failing.get(`#${described}`).text()).toContain('Champ requis')

        healthy.unmount()
        failing.unmount()
    })
})
