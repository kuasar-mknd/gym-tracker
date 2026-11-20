import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BoutonDesReglagesDeLaSeance from '@/Components/Workout/BoutonDesReglagesDeLaSeance.vue'
import { ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'

const monter = (props = {}) => mount(BoutonDesReglagesDeLaSeance, { props, global: { directives: { press: {} } } })

/*
 * Le même bouton sert l'en-tête mobile et celui de bureau (lot 8, #2434) : il
 * porte son nom, rend son clic au parent, et se désactive avec la raison.
 */
describe('BoutonDesReglagesDeLaSeance', () => {
    it('porte son nom accessible, sans désactivation par défaut', async () => {
        const bouton = monter().get('button')

        expect(bouton.attributes('aria-label')).toBe('Paramètres de la séance')
        expect(bouton.attributes('disabled')).toBeUndefined()
        expect(bouton.attributes('aria-describedby')).toBeUndefined()
    })

    it('se désactive en renvoyant à l’état de la séance en attente', () => {
        const bouton = monter({ desactive: true }).get('button')

        expect(bouton.attributes('disabled')).toBeDefined()
        expect(bouton.attributes('aria-describedby')).toBe(ID_DE_L_ETAT_DE_LA_SEANCE)
    })
})
