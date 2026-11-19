import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ToastsDuLayout from '@/Components/UI/ToastsDuLayout.vue'
import { abandonner, DELAI_POUR_ANNULER_MS, proposerUneAnnulation } from '@/Utils/suppressionAnnulable'

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { flash: {} } }) }))

const monter = () =>
    mount(ToastsDuLayout, {
        attachTo: document.body,
        global: { directives: { press: {} } },
    })

const annonce = () => ({ message: 'Série supprimée', porte: () => false, valider: vi.fn(), annuler: vi.fn() })

beforeEach(() => vi.useFakeTimers())

afterEach(() => {
    abandonner()
    vi.useRealTimers()
    document.body.innerHTML = ''
})

describe('ToastsDuLayout — la suppression qu’on peut annuler (#2355)', () => {
    it('shows nothing while no deletion waits', () => {
        const wrapper = monter()

        expect(wrapper.find('[dusk="toast-suppression"]').exists()).toBe(false)
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
        expect(wrapper.find('[dusk="toast-suppression"]').exists()).toBe(false)
    })

    it('goes away with the deletion once the time has run out', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        await wrapper.vm.$nextTick()

        expect(s.valider).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[dusk="toast-suppression"]').exists()).toBe(false)
    })

    it('holds the countdown while the pointer or the focus is on it', async () => {
        const wrapper = monter()
        const s = annonce()

        proposerUneAnnulation(s)
        await wrapper.vm.$nextTick()

        await wrapper.get('[dusk="toast-suppression"]').trigger('focusin')
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS * 2)
        expect(s.valider).not.toHaveBeenCalled()

        await wrapper.get('[dusk="toast-suppression"]').trigger('focusout')
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)
        expect(s.valider).toHaveBeenCalledTimes(1)
    })
})
