import { describe, it, expect, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

import QuickActions from '@/Components/Dashboard/QuickActions.vue'
import { CLE_DE_LA_SEANCE_EN_COURS } from '@/Utils/cleDeLaSeanceEnCours'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

const monter = (props = {}, seanceEnCours = null) =>
    mount(QuickActions, {
        props,
        global: {
            provide: { [CLE_DE_LA_SEANCE_EN_COURS]: ref(seanceEnCours) },
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
        },
    })

/**
 * Le nom accessible d'une commande contient son libellé visible (WCAG 2.5.3) :
 * « Démarrer une nouvelle séance d'entraînement » ne contenait pas
 * « Démarrer séance », que la commande dictée à la voix visait.
 */
describe('QuickActions', () => {
    it('nomme chaque tuile par son libellé visible, ou par un nom qui le contient', () => {
        const wrapper = monter()

        for (const [selecteur, libelle] of [
            ['[dusk="start-workout-button"]', 'Démarrer une séance'],
            ['a', 'Mes modèles'],
        ]) {
            const tuile = wrapper.get(selecteur)
            const nom = tuile.attributes('aria-label') ?? tuile.text()

            expect(tuile.text()).toContain(libelle)
            expect(nom.toLowerCase()).toContain(libelle.toLowerCase())
        }
    })

    it('émet startWorkout au clic, et se verrouille pendant le démarrage', async () => {
        const wrapper = monter({ processing: true })

        expect(wrapper.get('[dusk="start-workout-button"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[dusk="start-workout-button"]').attributes('aria-busy')).toBe('true')

        const libre = monter()
        await libre.get('[dusk="start-workout-button"]').trigger('click')

        expect(libre.emitted('startWorkout')).toHaveLength(1)
    })

    it('garde un seul aplat orange, sans dégradé ni halo', () => {
        const html = monter().html()

        expect(html.match(/accent-fill/g)).toHaveLength(1)
        expect(html).not.toMatch(/glow|bg-linear|bg-gradient|tertiary/)
    })

    it('masque « Démarrer une séance » quand une séance est en cours, et garde « Mes modèles »', () => {
        const wrapper = monter({}, { id: 12, name: 'Push' })

        expect(wrapper.find('[dusk="start-workout-button"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Démarrer une séance')
        expect(wrapper.text()).toContain('Mes modèles')
        expect(wrapper.get('section').classes()).toContain('grid-cols-1')
        expect(wrapper.get('a').classes()).toContain('items-center')
        expect(wrapper.get('a').classes()).not.toContain('flex-col')
    })

    it('remontre « Démarrer une séance » dès que plus aucune séance n’est en cours', () => {
        const sansSeance = monter({}, null)

        expect(sansSeance.get('[dusk="start-workout-button"]').exists()).toBe(true)
        expect(sansSeance.get('section').classes()).toContain('grid-cols-2')
    })
})
