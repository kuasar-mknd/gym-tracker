import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ErreurDeSaisie from '@/Components/Workout/ErreurDeSaisie.vue'
import { routeDeTest } from '../composables/seanceHorsLigne'

let routeAvant

beforeEach(() => {
    routeAvant = globalThis.route
    globalThis.route = routeDeTest
})

afterEach(() => {
    globalThis.route = routeAvant
})

const monter = (props) =>
    mount(ErreurDeSaisie, { props, global: { mocks: { route: routeDeTest }, stubs: { Transition: false } } })

describe('ErreurDeSaisie', () => {
    it('sans persistance, n’offre ni fermeture ni reconnexion', () => {
        const wrapper = monter({ message: 'Refus' })

        expect(wrapper.text()).toContain('Refus')
        expect(wrapper.find('[dusk="set-edit-error-fermer"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="set-edit-error-reconnexion"]').exists()).toBe(false)
    })

    it('persistant, offre la fermeture, au gabarit du bouton, et la signale au parent', async () => {
        const wrapper = monter({ message: 'Stockage plein', persistant: true })
        const fermer = wrapper.get('[dusk="set-edit-error-fermer"]')

        expect(fermer.classes()).toContain('min-h-touch')

        await fermer.trigger('click')

        expect(wrapper.emitted('fermer')).toHaveLength(1)
    })

    it('rend le focus au contenu principal quand « Fermer » retire le bouton qui l’avait', async () => {
        const principal = document.createElement('main')
        principal.id = 'main-content'
        principal.tabIndex = -1
        document.body.appendChild(principal)

        const wrapper = mount(ErreurDeSaisie, {
            props: {
                message: 'Stockage plein',
                persistant: true,
                onFermer: () => wrapper.setProps({ message: null }),
            },
            attachTo: document.body,
            global: { mocks: { route: routeDeTest }, stubs: { Transition: false } },
        })
        const fermer = wrapper.get('[dusk="set-edit-error-fermer"]')
        fermer.element.focus()
        expect(document.activeElement).toBe(fermer.element)

        await fermer.trigger('click')
        await flushPromises()

        expect(wrapper.find('[dusk="set-edit-error-fermer"]').exists()).toBe(false)
        expect(document.activeElement).toBe(principal)

        wrapper.unmount()
        principal.remove()
    })

    it('session expirée, offre un lien « Se reconnecter » vers la connexion', () => {
        const wrapper = monter({ message: 'Session expirée', persistant: true, reconnexion: true })
        const lien = wrapper.get('[dusk="set-edit-error-reconnexion"]')

        expect(lien.text()).toBe('Se reconnecter')
        expect(lien.attributes('href')).toBe('/login')
        expect(lien.classes()).toContain('min-h-touch')
    })
})
