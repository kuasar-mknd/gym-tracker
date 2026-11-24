import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * Two defects found by auditing the interface rather than by writing tests,
 * each pinned here so the fix cannot quietly come undone.
 */
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'

describe('a row that swipes', () => {
    const mountRow = () =>
        mount(SwipeableRow, {
            props: { hasRightAction: true },
            global: { directives: { press: {} } },
            slots: { default: '<p>Rowing barre</p>', 'action-right': '<button>Supprimer</button>' },
        })

    /** The foreground layer, which is the element that moves. */
    const foreground = (wrapper) => wrapper.get('.z-10')

    const swipe = async (wrapper, from, to) => {
        await foreground(wrapper).trigger('touchstart', { touches: [{ clientX: from, clientY: 0 }] })
        await foreground(wrapper).trigger('touchmove', { touches: [{ clientX: to, clientY: 0 }] })
    }

    it('goes back to rest when the system takes the gesture away', async () => {
        const wrapper = mountRow()

        await swipe(wrapper, 200, 160)
        expect(foreground(wrapper).attributes('style')).toContain('translateX')

        // touchcancel fires when the browser or the OS claims the touch — a
        // call arriving, the notification shade, the back gesture from the
        // edge. Unhandled, the row stayed frozen mid-swipe with its actions
        // half-revealed, and no transition to carry it back.
        await foreground(wrapper).trigger('touchcancel')

        const style = foreground(wrapper).attributes('style')

        expect(style).toContain('translateX(0px)')
        expect(style).not.toContain('transition: none')

        wrapper.unmount()
    })

    it('still settles normally when the finger is simply lifted', async () => {
        const wrapper = mountRow()

        await swipe(wrapper, 200, 199)
        await foreground(wrapper).trigger('touchend')

        expect(foreground(wrapper).attributes('style')).toContain('translateX(0px)')

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
