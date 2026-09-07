import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { useInvitationDInstallation } from '@/composables/useInvitationDInstallation'

const CLEF = 'gym-tracker:invitation-installation-refusee'

let montages = []

const monter = () => {
    let etat
    const wrapper = mount({
        setup() {
            etat = useInvitationDInstallation()

            return () => null
        },
    })
    montages.push(wrapper)

    return etat
}

const inviter = () => {
    const evenement = new Event('beforeinstallprompt')
    evenement.prompt = vi.fn()
    evenement.userChoice = Promise.resolve({ outcome: 'accepted' })
    window.dispatchEvent(evenement)

    return evenement
}

const poserLePlatforme = (agent, standalone = false) => {
    Object.defineProperty(window.navigator, 'userAgent', { value: agent, configurable: true })
    Object.defineProperty(window.navigator, 'standalone', { value: standalone, configurable: true })
}

beforeEach(() => {
    window.localStorage.clear()
    poserLePlatforme('Mozilla/5.0 (Linux; Android 14)')
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
})

afterEach(() => {
    for (const wrapper of montages.splice(0)) {
        wrapper.unmount()
    }
})

describe('useInvitationDInstallation', () => {
    it('ne propose rien tant que le navigateur ne l’offre pas', () => {
        const { visible } = monter()

        expect(visible.value).toBe(false)
    })

    it('propose dès que le navigateur sait installer, et retient l’évènement', async () => {
        const { visible, forme, installer } = monter()

        const evenement = inviter()

        expect(visible.value).toBe(true)
        expect(forme.value).toBe('native')

        await installer()

        expect(evenement.prompt).toHaveBeenCalledOnce()
        expect(visible.value).toBe(false)
    })

    /** Sans cela, Chrome affiche sa propre barre en bas de page par-dessus la nôtre. */
    it('empêche l’invite native du navigateur', () => {
        monter()

        const evenement = new Event('beforeinstallprompt', { cancelable: true })
        evenement.prompt = vi.fn()
        window.dispatchEvent(evenement)

        expect(evenement.defaultPrevented).toBe(true)
    })

    it('explique la marche à suivre sur iPhone, faute d’évènement', () => {
        poserLePlatforme('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)')

        const { visible, forme } = monter()

        expect(visible.value).toBe(true)
        expect(forme.value).toBe('ios')
    })

    it('se tait quand l’application est déjà installée', () => {
        poserLePlatforme('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', true)

        expect(monter().visible.value).toBe(false)
    })

    it('se tait aussi quand elle tourne en mode autonome', () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true })

        monter()
        inviter()

        expect(montages).toHaveLength(1)
        expect(monter().visible.value).toBe(false)
    })

    it('ne revient plus après un refus', () => {
        const premier = monter()
        inviter()
        premier.refuser()

        expect(premier.visible.value).toBe(false)
        expect(window.localStorage.getItem(CLEF)).toBe('1')

        poserLePlatforme('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)')

        expect(monter().visible.value).toBe(false)
    })

    it('reste possible quand le stockage est bloqué', () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })

        const { visible, refuser } = monter()
        inviter()

        expect(visible.value).toBe(true)
        expect(() => refuser()).not.toThrow()
        expect(visible.value).toBe(false)

        setItem.mockRestore()
        getItem.mockRestore()
    })
})
