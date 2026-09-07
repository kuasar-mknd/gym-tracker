import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import InvitationDInstallation from '@/Components/UI/InvitationDInstallation.vue'

const monter = () =>
    mount(InvitationDInstallation, {
        global: { directives: { press: {} }, stubs: { GlassCard: { template: '<div><slot /></div>' } } },
    })

const inviter = () => {
    const evenement = new Event('beforeinstallprompt')
    evenement.prompt = vi.fn()
    evenement.userChoice = Promise.resolve({ outcome: 'accepted' })
    window.dispatchEvent(evenement)
}

beforeEach(() => {
    window.localStorage.clear()
    Object.defineProperty(window.navigator, 'userAgent', {
        value: 'Mozilla/5.0 (Linux; Android 14)',
        configurable: true,
    })
    Object.defineProperty(window.navigator, 'standalone', { value: false, configurable: true })
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
})

describe('InvitationDInstallation', () => {
    it('ne dessine rien tant qu’il n’y a rien à proposer', () => {
        expect(monter().find('[dusk="invitation-installation"]').exists()).toBe(false)
    })

    it('propose le bouton quand le navigateur sait installer', async () => {
        const wrapper = monter()

        inviter()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="invitation-installation"]').exists()).toBe(true)
        expect(wrapper.find('[dusk="installer-application"]').exists()).toBe(true)
    })

    /** Sur iPhone il n'y a pas d'évènement : il faut dire le geste, pas offrir un bouton. */
    it('explique le geste sur iPhone, sans bouton qui ne ferait rien', async () => {
        Object.defineProperty(window.navigator, 'userAgent', {
            value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
            configurable: true,
        })

        const wrapper = monter()
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('Sur l’écran d’accueil'.replace(/’/g, "'"))
        expect(wrapper.find('[dusk="installer-application"]').exists()).toBe(false)
    })

    it('se retire quand on la refuse', async () => {
        const wrapper = monter()

        inviter()
        await wrapper.vm.$nextTick()
        await wrapper.get('button[aria-label="Ne plus proposer l\'installation"]').trigger('click')

        expect(wrapper.find('[dusk="invitation-installation"]').exists()).toBe(false)
    })
})
