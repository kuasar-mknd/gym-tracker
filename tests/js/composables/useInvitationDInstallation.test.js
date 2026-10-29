import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { jsRoot } from '../conventions/sourceFiles'
import { installerLInvitationDInstallation, useInvitationDInstallation } from '@/composables/useInvitationDInstallation'

const CLEF = 'gym-tracker:invitation-installation-refusee'

let montages = []

/** L'écoute du document, posée comme `main.js` la pose (#2114). */
let nePlusEcouter = () => {}

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

/** Annulable, comme celui du navigateur : sans cela, `defaultPrevented` reste faux quoi qu'il arrive. */
const inviter = () => {
    const evenement = new Event('beforeinstallprompt', { cancelable: true })
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
    nePlusEcouter = installerLInvitationDInstallation()
})

afterEach(() => {
    for (const wrapper of montages.splice(0)) {
        wrapper.unmount()
    }

    nePlusEcouter()
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

    /*
     * L'invite native ne s'ouvre qu'une fois par évènement : un second appui
     * pendant qu'elle est ouverte ne rappelle pas `prompt()`, que le navigateur
     * refuserait (#2114).
     */
    it('n’ouvre l’invite qu’une fois, même sur un second appui', async () => {
        const { installer } = monter()
        const evenement = inviter()

        await Promise.all([installer(), installer()])

        expect(evenement.prompt).toHaveBeenCalledOnce()
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

        /*
         * Au chargement suivant, le refus tenu en mémoire est oublié : sur
         * iPhone, où la carte se montre sans évènement, seule la clé rangée la
         * retient (#2114).
         */
        nePlusEcouter()
        nePlusEcouter = installerLInvitationDInstallation()

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

describe('l’écoute du document', () => {
    /*
     * Le retrait de l'écoute oublie ce qu'elle a capté : un document neuf, et
     * un cas de test qui ne reçoit rien du précédent (#2114).
     */
    it('oublie, à son retrait, l’évènement et le refus qu’elle tenait', () => {
        inviter()
        monter().refuser()
        window.localStorage.clear()
        inviter()

        nePlusEcouter()
        const seconde = installerLInvitationDInstallation()

        try {
            expect(monter().visible.value).toBe(false)

            inviter()

            expect(monter().visible.value).toBe(true)
        } finally {
            seconde()
        }
    })

    it('n’écoute plus une fois retirée', () => {
        nePlusEcouter()

        const evenement = inviter()

        expect(evenement.defaultPrevented).toBe(false)
        expect(monter().visible.value).toBe(false)
    })

    it('ne double pas l’écoute quand elle est posée deux fois', () => {
        const encore = installerLInvitationDInstallation()
        const evenement = new Event('beforeinstallprompt', { cancelable: true })
        const empecher = vi.spyOn(evenement, 'preventDefault')

        window.dispatchEvent(evenement)
        encore()

        expect(empecher).toHaveBeenCalledOnce()
    })
})

describe('le branchement', () => {
    /*
     * Le navigateur émet `beforeinstallprompt` quand il veut, sur n'importe
     * quelle page, et une seule fois par document : `main.js` pose l'écoute
     * d'emblée, avant d'attendre le démarrage dans la coquille, et l'accueil ne
     * fait plus que lire ce qu'elle a capté (#2114).
     */
    it('est posé par main.js, avant le démarrage dans la coquille et le montage de l’application', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLInvitationDInstallation()')

        expect(main).toContain(
            "import { installerLInvitationDInstallation } from '@/composables/useInvitationDInstallation'",
        )
        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('const demarrerLApplication = () => {'))
        expect(pose).toBeLessThan(main.indexOf('demarrerHorsLigne().then('))
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})
