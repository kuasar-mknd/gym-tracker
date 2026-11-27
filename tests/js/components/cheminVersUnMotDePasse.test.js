import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import CheminVersUnMotDePasse from '@/Components/Form/CheminVersUnMotDePasse.vue'

const page = { props: { auth: { user: { email: 'une-adresse-tres-longue-sans-tiret-ni-point@exemple.org' } } } }

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }))

const monter = (props = {}) => mount(CheminVersUnMotDePasse, { props: { fournisseurDeConnexion: 'Google', ...props } })

/*
 * Une adresse sans tiret ne se coupe pas toute seule : sans `break-words`, elle
 * élargit le paragraphe et la page défile de côté à 320 px (#2434).
 */
describe('l’aide pour obtenir un mot de passe', () => {
    it.each([
        ['quand le lien peut partir', true],
        ['quand le lien ne peut pas partir', false],
    ])('laisse l’adresse se couper %s', (_cas, peutRecevoir) => {
        const paragraphe = monter({ peutRecevoirLeLienDeReinitialisation: peutRecevoir }).get('p')

        expect(paragraphe.classes()).toContain('break-words')
        expect(paragraphe.text()).toContain('une-adresse-tres-longue-sans-tiret-ni-point@exemple.org')
    })

    it('est une carte calme de la charte', () => {
        const carte = monter().get('[data-testid="profile-social-password-help"]')

        expect(carte.classes()).toEqual(expect.arrayContaining(['bg-surface-card', 'border-accent-warning/60']))
    })

    it('ne montre rien sans fournisseur de connexion', () => {
        expect(
            monter({ fournisseurDeConnexion: null }).find('[data-testid="profile-social-password-help"]').exists(),
        ).toBe(false)
    })
})
