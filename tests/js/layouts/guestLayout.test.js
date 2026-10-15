import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

let page = { props: {} }

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}))

/** La marque se teste dans useAbonnementPush.test.js ; ici, seulement l'appel. */
const marquerLAbonnementARetransmettre = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useAbonnementPush', () => ({ marquerLAbonnementARetransmettre }))

import GuestLayout from '@/Layouts/GuestLayout.vue'

const monter = (auth) => {
    page = { props: { auth } }

    return mount(GuestLayout, {
        slots: { default: '<p data-testid="formulaire">formulaire</p>' },
        global: { stubs: { LiquidBackground: true } },
    })
}

beforeEach(() => {
    vi.clearAllMocks()
})

describe('GuestLayout', () => {
    /*
     * Une page d'invité dit que l'appareil n'a plus de session. Elle a pu être
     * fermée par un changement de mot de passe fait ailleurs, qui a retiré
     * l'abonnement push de cet appareil : la connexion suivante au même compte
     * doit le retransmettre, quoi qu'en dise le mémo. La vérification de
     * l'adresse et la confirmation du mot de passe prennent ce gabarit sous
     * une session ouverte : rien n'y a été retiré.
     */
    it('marque l’abonnement push à retransmettre sur une page d’invité, pas sous une session ouverte', () => {
        const sousUneSession = monter({ user: { id: 42 } })

        expect(sousUneSession.find('[data-testid="formulaire"]').exists()).toBe(true)
        expect(marquerLAbonnementARetransmettre).not.toHaveBeenCalled()

        monter({ user: null })

        expect(marquerLAbonnementARetransmettre).toHaveBeenCalledTimes(1)
    })

    /**
     * Le logo de l'application, seul au-dessus de la carte. Le lien vers
     * l'accueil se lit « GymTracker » : le dessin est muet, et le nom, caché,
     * reste lu.
     */
    it('montre le logo seul, en lien vers l’accueil qui se lit par le nom de l’application', () => {
        const accueil = monter({ user: null }).get('a[href="/"]')

        expect(accueil.find('[data-testid="logo-de-l-application"]').exists()).toBe(true)
        expect(accueil.get('[data-testid="logo-de-l-application"]').classes()).toContain('size-20')
        expect(accueil.text()).toBe('GymTracker')
        expect(accueil.get('[data-testid="logo-de-l-application"] + span').classes()).toContain('sr-only')
    })
})
