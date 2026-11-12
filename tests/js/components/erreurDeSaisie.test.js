import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ErreurDeSaisie from '@/Components/Workout/ErreurDeSaisie.vue'

beforeEach(() => {
    globalThis.route = (nom) => `/${nom}`
})

const monter = (props) =>
    mount(ErreurDeSaisie, { props, global: { mocks: { route: globalThis.route }, stubs: { Transition: false } } })

describe('ErreurDeSaisie', () => {
    it('sans persistance, n’offre ni fermeture ni reconnexion', () => {
        const wrapper = monter({ message: 'Refus' })

        expect(wrapper.text()).toContain('Refus')
        expect(wrapper.find('[dusk="set-edit-error-fermer"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="set-edit-error-reconnexion"]').exists()).toBe(false)
    })

    it('persistant, offre la fermeture et la signale au parent', async () => {
        const wrapper = monter({ message: 'Stockage plein', persistant: true })

        await wrapper.get('[dusk="set-edit-error-fermer"]').trigger('click')

        expect(wrapper.emitted('fermer')).toHaveLength(1)
    })

    it('session expirée, offre un lien « Se reconnecter » vers la connexion', () => {
        const wrapper = monter({ message: 'Session expirée', persistant: true, reconnexion: true })
        const lien = wrapper.get('[dusk="set-edit-error-reconnexion"]')

        expect(lien.text()).toBe('Se reconnecter')
        expect(lien.attributes('href')).toBe('/login')
    })
})
