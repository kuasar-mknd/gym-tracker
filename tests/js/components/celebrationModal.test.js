import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const page = { props: {} }
const post = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        post: (...args) => post(...args),
    },
}))

import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'

/**
 * jsdom does not implement <dialog>, so these stand in for the real thing.
 * That is also the limit of what this file can prove: it pins that the
 * component opens the dialog *modally*, which is the mechanism that supplies
 * the focus trap, the Escape handling and the inert background — it cannot
 * exercise those behaviours themselves. They are the browser's, not ours.
 */
beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${params?.id ?? ''}`

    HTMLDialogElement.prototype.showModal = function showModal() {
        this.open = true
    }
    HTMLDialogElement.prototype.close = function close() {
        this.open = false
        this.dispatchEvent(new Event('close'))
    }
})

/** Un badge tel que le partage `HandleInertiaRequests` : la notification, et ce que le serveur lit sur le badge. */
const anAchievement = (id = 'notif-1') => ({
    id,
    name: 'Premier record',
    icon: '🥇',
    description: 'Tu as battu ton premier record.',
    data: { type: 'achievement', message: 'Félicitations ! Tu as débloqué le badge : Premier record.' },
})

const mountModal = async (props = { is_testing: false, auth: { user: { latest_achievement: anAchievement() } } }) => {
    page.props = props

    const wrapper = mount(CelebrationModal, {
        global: {
            directives: { press: {} },
            stubs: { Teleport: true },
        },
        attachTo: document.body,
    })

    // Le montage ouvre le dialogue sur le badge que la page porte déjà.
    await nextTick()

    return wrapper
}

const dialogOf = (wrapper) => wrapper.find('dialog').element

beforeEach(() => {
    post.mockReset()
})

describe('CelebrationModal', () => {
    it('is a native dialog carrying the accessible name and description', async () => {
        const wrapper = await mountModal()
        const dialog = dialogOf(wrapper)

        expect(dialog.tagName).toBe('DIALOG')
        expect(dialog.getAttribute('aria-labelledby')).toBe('achievement-title')
        expect(dialog.getAttribute('aria-describedby')).toBe('achievement-name achievement-description')

        wrapper.unmount()
    })

    /**
     * It used to be a plain div wearing role="dialog" and aria-modal="true".
     * Both are claims; neither keeps Tab inside the overlay, and the overlay
     * covers the whole screen.
     */
    it('opens modally when an achievement arrives', async () => {
        const wrapper = await mountModal()

        expect(dialogOf(wrapper).open).toBe(true)
        expect(wrapper.text()).toContain('Premier record')

        wrapper.unmount()
    })

    /**
     * La notification ne porte ni l'icône ni le nom du badge : la modale les y
     * lisait, et célébrait un carré vide, sans nom (#2052). Le serveur les joint
     * désormais, avec la description, que le nom annoncé complète.
     */
    it('montre l’icône, le nom et la description que le serveur joint au badge', async () => {
        const wrapper = await mountModal()

        expect(wrapper.find('[aria-hidden="true"]').text()).toBe('🥇')
        expect(wrapper.find('#achievement-name').text()).toBe('Premier record')
        expect(wrapper.find('#achievement-description').text()).toBe('Tu as battu ton premier record.')

        wrapper.unmount()
    })

    it('d’un badge supprimé depuis, montre le message de la notification sous l’icône par défaut, sans nom', async () => {
        const supprime = { ...anAchievement(), name: null, icon: null, description: null }
        const wrapper = await mountModal({ is_testing: false, auth: { user: { latest_achievement: supprime } } })

        expect(dialogOf(wrapper).open).toBe(true)
        expect(wrapper.find('[aria-hidden="true"]').text()).toBe('🏆')
        expect(wrapper.find('#achievement-name').exists()).toBe(false)
        expect(wrapper.find('#achievement-description').text()).toBe(
            'Félicitations ! Tu as débloqué le badge : Premier record.',
        )

        wrapper.unmount()
    })

    it('does not announce a second nested dialog', async () => {
        const wrapper = await mountModal()

        expect(wrapper.findAll('[role="dialog"]')).toHaveLength(0)
        expect(wrapper.findAll('[aria-modal]')).toHaveLength(0)

        wrapper.unmount()
    })

    it('stays shut while browser tests are running', async () => {
        const wrapper = await mountModal({ is_testing: true, auth: { user: { latest_achievement: anAchievement() } } })

        expect(dialogOf(wrapper).open).toBe(false)

        wrapper.unmount()
    })

    it('closes the dialog and marks the notification read', async () => {
        const wrapper = await mountModal()

        await wrapper.find('[dusk="celebration-dismiss"]').trigger('click')

        expect(dialogOf(wrapper).open).toBe(false)
        expect(post).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    /**
     * Escape closes a native dialog on its own, so the component learns about
     * it from the close event rather than from its own handler. Closing then
     * re-entering that handler is the trap: the notification would be marked
     * as read twice, one of them for an id already cleared.
     */
    it('marks the notification read exactly once when Escape closes it', async () => {
        const wrapper = await mountModal()
        const dialog = dialogOf(wrapper)

        dialog.close()
        await wrapper.vm.$nextTick()

        expect(dialog.open).toBe(false)
        expect(post).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    /**
     * La carte se borne à la zone sûre (#2043), mais ne prend que 12 px
     * au-dessus et au-dessous d'elle. Avec 24 px (`py-sure-6`, borne
     * `hauteur-sure-12`), un téléphone couché sans encoche de 375 px de haut
     * ne lui laissait que 327 px pour 342 : elle défilait, et le bas de son
     * bouton touchait son bord arrondi. Elle tient entière dès 366 px.
     */
    it('garde 12 px au-dessus et au-dessous de la carte, bornée à la zone sûre', async () => {
        const wrapper = await mountModal()
        const carte = wrapper.find('#achievement-title').element.closest('.rounded-3xl.border')

        expect(carte.parentElement.classList).toContain('py-sure-3')
        expect(carte.classList).toContain('hauteur-sure-6')
        expect(carte.classList).toContain('overflow-y-auto')

        wrapper.unmount()
    })
})
