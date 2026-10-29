import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import InvitationDInstallation from '@/Components/UI/InvitationDInstallation.vue'
import { installerLInvitationDInstallation } from '@/composables/useInvitationDInstallation'

const CLEF_DU_REFUS = 'gym-tracker:invitation-installation-refusee'

/*
 * L'écoute du document, posée comme `main.js` la pose, puis retirée après
 * chaque cas : son retrait oublie aussi ce qu'elle a capté, et aucun
 * évènement ni refus ne passe d'un cas à l'autre (#2114).
 */
let nePlusEcouter = () => {}
const montees = []

const monter = () => {
    const wrapper = mount(InvitationDInstallation, {
        global: { directives: { press: {} }, stubs: { GlassCard: { template: '<div><slot /></div>' } } },
    })
    montees.push(wrapper)

    return wrapper
}

const inviter = () => {
    const evenement = new Event('beforeinstallprompt', { cancelable: true })
    evenement.prompt = vi.fn()
    evenement.userChoice = Promise.resolve({ outcome: 'accepted' })
    window.dispatchEvent(evenement)

    return evenement
}

const carte = (wrapper) => wrapper.find('[dusk="invitation-installation"]')

const refuser = (wrapper) => wrapper.get('button[aria-label="Ne plus proposer l\'installation"]').trigger('click')

/** Sur iPhone, aucun évènement : la carte se montre d'elle-même, avec le geste à faire. */
const surIPhone = () =>
    Object.defineProperty(window.navigator, 'userAgent', {
        value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
        configurable: true,
    })

beforeEach(() => {
    window.localStorage.clear()
    Object.defineProperty(window.navigator, 'userAgent', {
        value: 'Mozilla/5.0 (Linux; Android 14)',
        configurable: true,
    })
    Object.defineProperty(window.navigator, 'standalone', { value: false, configurable: true })
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
    nePlusEcouter = installerLInvitationDInstallation()
})

afterEach(() => {
    for (const wrapper of montees.splice(0)) {
        wrapper.unmount()
    }

    nePlusEcouter()
    vi.restoreAllMocks()
})

describe('InvitationDInstallation', () => {
    it('ne dessine rien tant qu’il n’y a rien à proposer', () => {
        expect(carte(monter()).exists()).toBe(false)
    })

    it('propose le bouton quand le navigateur sait installer', async () => {
        const wrapper = monter()

        inviter()
        await wrapper.vm.$nextTick()

        expect(carte(wrapper).exists()).toBe(true)
        expect(wrapper.find('[dusk="installer-application"]').exists()).toBe(true)
    })

    /** Sur iPhone il n'y a pas d'évènement : il faut dire le geste, pas offrir un bouton. */
    it('explique le geste sur iPhone, sans bouton qui ne ferait rien', async () => {
        surIPhone()

        const wrapper = monter()
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('Sur l’écran d’accueil'.replace(/’/g, "'"))
        expect(wrapper.find('[dusk="installer-application"]').exists()).toBe(false)
    })

    /*
     * Sur iPhone, aucun évènement à effacer : c'est le refus de ce document qui
     * retire la carte, celle de l'accueil affiché comprise (#2114).
     */
    it('se retire sur iPhone quand on la refuse, et le dit à l’accueil', async () => {
        surIPhone()

        const wrapper = monter()
        await refuser(wrapper)

        expect(carte(wrapper).exists()).toBe(false)
        expect(wrapper.emitted('visibilite')).toEqual([[true], [false]])
    })

    /** L'accueil ne montre qu'une invitation à la fois, et celle-ci passe d'abord. */
    it('dit à l’accueil quand elle se montre et quand elle se retire', async () => {
        const wrapper = monter()

        inviter()
        await wrapper.vm.$nextTick()
        await refuser(wrapper)
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('visibilite')).toEqual([[true], [false]])
    })

    it('se retire quand on la refuse', async () => {
        const wrapper = monter()

        inviter()
        await wrapper.vm.$nextTick()
        await refuser(wrapper)

        expect(carte(wrapper).exists()).toBe(false)
    })
})

/*
 * Chromium n'émet `beforeinstallprompt` qu'au chargement du document, et une
 * visite d'Inertia ne le recharge pas : l'évènement se garde pour tout le
 * document, pas pour le seul passage de l'accueil (#2114).
 */
describe('un seul évènement pour tout le document', () => {
    it('revient après un aller-retour vers une autre page, sans nouvel évènement', async () => {
        const accueil = monter()

        inviter()
        await accueil.vm.$nextTick()

        expect(carte(accueil).exists()).toBe(true)

        // La séance ouverte, puis le retour à l'accueil : deux visites d'Inertia.
        accueil.unmount()
        const auRetour = monter()
        await auRetour.vm.$nextTick()

        expect(carte(auRetour).exists()).toBe(true)
        expect(auRetour.find('[dusk="installer-application"]').exists()).toBe(true)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBeNull()
    })

    it('se montre au montage quand le navigateur a parlé sur une autre page', async () => {
        const evenement = inviter()

        // L'invite du navigateur est écartée : la carte la remplace.
        expect(evenement.defaultPrevented).toBe(true)

        const accueil = monter()
        await accueil.vm.$nextTick()

        expect(carte(accueil).exists()).toBe(true)
        expect(accueil.emitted('visibilite')).toEqual([[true]])
    })

    it('« Installer » ouvre l’invite de l’évènement gardé, une fois, et la carte ne revient pas', async () => {
        const evenement = inviter()
        const accueil = monter()

        await accueil.get('[dusk="installer-application"]').trigger('click')
        await flushPromises()

        expect(evenement.prompt).toHaveBeenCalledOnce()
        expect(carte(accueil).exists()).toBe(false)

        accueil.unmount()

        expect(carte(monter()).exists()).toBe(false)
    })

    it('ne revient pas après une installation faite pendant qu’une autre page est affichée', async () => {
        const accueil = monter()
        inviter()
        await accueil.vm.$nextTick()
        accueil.unmount()

        window.dispatchEvent(new Event('appinstalled'))

        expect(carte(monter()).exists()).toBe(false)
    })

    it('ne revient pas après un refus, même quand le navigateur réémet l’évènement', async () => {
        const accueil = monter()
        inviter()
        await accueil.vm.$nextTick()
        await refuser(accueil)
        accueil.unmount()

        const reemis = inviter()

        expect(carte(monter()).exists()).toBe(false)
        // « Ne plus proposer l'installation » vaut aussi pour l'invite du navigateur.
        expect(reemis.defaultPrevented).toBe(true)
        expect(window.localStorage.getItem(CLEF_DU_REFUS)).toBe('1')
    })

    it('tient le refus pour tout le document quand le stockage refuse d’écrire', async () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('stockage refusé')
        })

        const accueil = monter()
        inviter()
        await accueil.vm.$nextTick()
        await refuser(accueil)
        accueil.unmount()

        // De retour à l'accueil, le navigateur réémet l'évènement.
        const auRetour = monter()
        inviter()
        await auRetour.vm.$nextTick()

        expect(carte(auRetour).exists()).toBe(false)
    })

    /*
     * Le refus vaut pour l'appareil, comme celui des notifications : la
     * déconnexion n'efface pas sa clé, et le compte suivant, dans le même
     * document ou dans un autre, ne revoit pas la carte.
     */
    it('tient le refus d’un compte à l’autre, dans ce document comme au suivant', async () => {
        const accueil = monter()
        inviter()
        await accueil.vm.$nextTick()
        await refuser(accueil)
        accueil.unmount()

        // Déconnexion puis connexion d'un autre compte : des visites d'Inertia.
        inviter()
        expect(carte(monter()).exists()).toBe(false)

        // Un document neuf : rien en mémoire, seule la clé de l'appareil reste.
        nePlusEcouter()
        nePlusEcouter = installerLInvitationDInstallation()
        inviter()

        expect(carte(monter()).exists()).toBe(false)
    })
})
