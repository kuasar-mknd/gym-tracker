import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

/**
 * La carte seule : ce qu'elle montre selon l'état que le composable lui donne,
 * et ce que ses boutons déclenchent. Le composable a sa propre suite.
 */
const etat = vi.hoisted(() => ({ options: null, invitation: null }))

vi.mock('@/composables/useInvitationAuxNotifications', () => ({
    useInvitationAuxNotifications: (options) => {
        etat.options = options

        return etat.invitation
    },
}))

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { vapidPublicKey: 'BAbc', auth: { user: { id: 42 } } } }),
}))

import InvitationAuxNotifications from '@/Components/UI/InvitationAuxNotifications.vue'

const monter = (props = {}) =>
    mount(InvitationAuxNotifications, {
        props,
        global: { stubs: { GlassCard: { template: '<div v-bind="$attrs"><slot /></div>' } } },
    })

beforeEach(() => {
    etat.options = null
    etat.invitation = {
        visible: ref(true),
        activee: ref(false),
        enCours: ref(false),
        etapeEnCours: ref(null),
        erreur: ref(null),
        activer: vi.fn(),
        refuser: vi.fn(),
        fermer: vi.fn(),
    }
})

const bouton = (wrapper, libelle) => wrapper.findAll('button').find((b) => b.text().includes(libelle))

describe('InvitationAuxNotifications', () => {
    it('ne dessine rien tant qu’il n’y a rien à proposer', () => {
        etat.invitation.visible.value = false

        expect(monter().find('[dusk="invitation-notifications"]').exists()).toBe(false)
    })

    it('transmet au composable la clé, le compte et la priorité de l’autre invitation', () => {
        monter({ uneAutreInvitationPasseAvant: true })

        expect(etat.options.vapidPublicKey).toBe('BAbc')
        expect(etat.options.utilisateurId).toBe(42)
        expect(etat.options.uneAutreInvitationPasseAvant()).toBe(true)
    })

    it('propose d’activer en action principale, et de refuser à côté', async () => {
        const wrapper = monter()

        const activer = bouton(wrapper, 'Activer')
        const refuser = bouton(wrapper, 'Non merci')

        // Accepter et refuser ne se ressemblent pas (.ai/rules/js.md).
        expect(activer.classes()).toContain('glass-button-primary')
        expect(refuser.classes()).toContain('bg-transparent')
        expect(refuser.classes()).not.toContain('glass-button-primary')

        await activer.trigger('click')
        await refuser.trigger('click')

        expect(etat.invitation.activer).toHaveBeenCalledTimes(1)
        expect(etat.invitation.refuser).toHaveBeenCalledTimes(1)
    })

    it('dit que seuls les records s’activent ici', () => {
        expect(monter().text()).toContain('records')
    })

    it('montre l’étape en cours sur le bouton pendant l’activation', () => {
        etat.invitation.enCours.value = true
        etat.invitation.etapeEnCours.value = 'Abonnement'

        const activer = monter().get('[dusk="activer-notifications"]')

        expect(activer.text()).toContain('Abonnement')
        expect(activer.attributes('aria-busy')).toBe('true')
    })

    it('annonce une erreur à voix haute', () => {
        etat.invitation.erreur.value = 'L’activation a échoué.'

        const alerte = monter().get('[role="alert"]')

        expect(alerte.text()).toBe('L’activation a échoué.')
    })

    it('confirme l’activation, renvoie au profil pour les rappels, et se ferme', async () => {
        etat.invitation.activee.value = true
        const wrapper = monter()

        expect(bouton(wrapper, 'Activer')).toBeUndefined()
        expect(wrapper.text()).toContain('profil')

        await wrapper.get('button[aria-label="Fermer"]').trigger('click')

        expect(etat.invitation.fermer).toHaveBeenCalledTimes(1)
        expect(etat.invitation.refuser).not.toHaveBeenCalled()
    })
})
