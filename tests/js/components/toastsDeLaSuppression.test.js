import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ToastsDuLayout from '@/Components/UI/ToastsDuLayout.vue'
import { abandonner, DELAI_POUR_ANNULER_MS, proposerUneAnnulation } from '@/Utils/suppressionAnnulable'

const pageStub = vi.hoisted(() => ({ props: { flash: {} } }))

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pageStub }))

const monter = () =>
    mount(ToastsDuLayout, {
        attachTo: document.body,
        global: { directives: { press: {} } },
    })

const annonce = () => ({ message: 'Série supprimée', porte: () => false, valider: vi.fn(), annuler: vi.fn() })

beforeEach(() => {
    pageStub.props.flash = {}
    vi.useFakeTimers()
})

afterEach(() => {
    abandonner()
    vi.useRealTimers()
    document.body.innerHTML = ''
})

describe('ToastsDuLayout — la suppression qu’on peut annuler (#2355)', () => {
    it('shows nothing while no deletion waits', () => {
        const wrapper = monter()

        expect(wrapper.find('[dusk="annuler-la-suppression"]').exists()).toBe(false)
    })

    it('keeps its live region in the page before there is anything to say, so the message is announced', () => {
        const wrapper = monter()

        expect(wrapper.get('[dusk="toast-suppression"]').attributes('role')).toBe('status')
    })

    it('announces the deletion politely, as a status and not as an alert', async () => {
        const wrapper = monter()

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()

        const toast = wrapper.get('[dusk="toast-suppression"]')

        expect(toast.attributes('role')).toBe('status')
        expect(toast.attributes('aria-live')).toBe('polite')
        expect(toast.text()).toContain('Série supprimée')
    })

    it('wears the design’s toast: thick glass, a 6 px state edge, and the same medium weight as the flash toasts', async () => {
        const wrapper = monter()

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()

        const panneau = wrapper.get('[dusk="toast-suppression"] div')

        expect(panneau.classes()).toEqual(
            expect.arrayContaining(['verre-epais', 'border-l-6!', 'border-l-accent-state!']),
        )
        expect(panneau.classes()).not.toContain('glass-panel-light')
        expect(panneau.get('p').classes()).toContain('font-medium')
        expect(panneau.get('p').classes()).not.toContain('font-bold')
    })

    it('offers a real button, reachable from the keyboard, that takes the deletion back', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()

        const bouton = wrapper.get('[dusk="annuler-la-suppression"]')

        expect(bouton.element.tagName).toBe('BUTTON')
        expect(bouton.element.getAttribute('tabindex')).not.toBe('-1')
        expect(bouton.text()).toBe('Annuler')

        await bouton.trigger('click')

        expect(s.annuler).toHaveBeenCalledTimes(1)
        expect(s.valider).not.toHaveBeenCalled()
        expect(wrapper.find('[dusk="annuler-la-suppression"]').exists()).toBe(false)
    })

    it('goes away with the deletion once the time has run out', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        await wrapper.vm.$nextTick()

        expect(s.valider).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[dusk="annuler-la-suppression"]').exists()).toBe(false)
    })

    it('holds the countdown while the pointer or the focus is on it', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()

        await wrapper.get('[dusk="annuler-la-suppression"]').trigger('focusin')
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS * 2)
        expect(s.valider).not.toHaveBeenCalled()

        await wrapper.get('[dusk="annuler-la-suppression"]').trigger('focusout')
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        expect(s.valider).toHaveBeenCalledTimes(1)
    })

    it('names its button for what it undoes', async () => {
        const wrapper = monter()

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[dusk="annuler-la-suppression"]').attributes('aria-label')).toBe('Annuler la suppression')
    })

    it('holds the countdown for a mouse, not for a touch whose emulated hover never ends', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()
        const carte = wrapper.get('[dusk="annuler-la-suppression"]').element.parentElement

        carte.dispatchEvent(Object.assign(new Event('pointerenter'), { pointerType: 'touch' }))
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        expect(s.valider).toHaveBeenCalledTimes(1)

        const seconde = annonce()
        proposerUneAnnulation(seconde)
        await wrapper.vm.$nextTick()
        const autre = wrapper.get('[dusk="annuler-la-suppression"]').element.parentElement

        autre.dispatchEvent(Object.assign(new Event('pointerenter'), { pointerType: 'mouse' }))
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS * 2)
        expect(seconde.valider).not.toHaveBeenCalled()

        autre.dispatchEvent(new Event('pointerleave'))
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        expect(seconde.valider).toHaveBeenCalledTimes(1)
    })

    it('moves the focus onto the button when the deletion came from the keyboard', async () => {
        const wrapper = monter()
        const matches = vi.spyOn(Element.prototype, 'matches').mockReturnValue(true)

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()
        await wrapper.vm.$nextTick()
        matches.mockRestore()

        expect(document.activeElement).toBe(wrapper.get('[dusk="annuler-la-suppression"]').element)
    })

    it('leaves the focus alone when the deletion came from a pointer', async () => {
        const wrapper = monter()
        const matches = vi.spyOn(Element.prototype, 'matches').mockReturnValue(false)

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()
        await wrapper.vm.$nextTick()
        matches.mockRestore()

        expect(document.activeElement).not.toBe(wrapper.get('[dusk="annuler-la-suppression"]').element)
    })

    it('stacks with the error toast in one column, so neither covers the other, and the error can be closed', async () => {
        pageStub.props.flash = { error: 'La série n’a pas pu être supprimée. Réessaie.' }
        const wrapper = monter()

        proposerUneAnnulation(annonce())
        await wrapper.vm.$nextTick()

        const alerte = wrapper.get('[role="alert"]')
        const region = wrapper.get('[dusk="toast-suppression"]')

        expect(alerte.classes()).not.toContain('fixed')
        expect(wrapper.element.classList.contains('fixed')).toBe(true)
        expect(wrapper.element.contains(alerte.element)).toBe(true)
        expect(wrapper.element.contains(region.element)).toBe(true)
        expect(alerte.text()).toContain('La série n’a pas pu être supprimée')

        await alerte.get('button').trigger('click')

        expect(pageStub.props.flash.error).toBeNull()
        expect(wrapper.find('[dusk="annuler-la-suppression"]').exists()).toBe(true)
    })
})
