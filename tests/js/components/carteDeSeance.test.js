import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import CarteDeSeance from '@/Components/Workout/CarteDeSeance.vue'

const ligne = (id, name, setsCount) => ({ id, exercise: { id, name }, sets_count: setsCount })

const seance = {
    id: 7,
    name: 'Push Day',
    started_at: '2026-07-29T08:00:00.000000Z',
    workout_lines: [ligne(11, 'Développé', 4), ligne(12, 'Dips', 3), ligne(13, 'Écarté', 3), ligne(14, 'Pompes', 2)],
}

const monter = (props = {}) =>
    mount(CarteDeSeance, {
        props: { seance, ...props },
        global: {
            mocks: { route: (nom, params) => `/${nom}/${params.workout}` },
            stubs: {
                SwipeableRow: { template: '<div><slot name="action-right" /><slot /></div>' },
                GlassCard: { template: '<div><slot /></div>' },
            },
        },
    })

describe('CarteDeSeance', () => {
    it('dit le nom de la séance, sa date en français et combien d’exercices elle compte', () => {
        const wrapper = monter()

        expect(wrapper.get('h4').text()).toBe('Push Day')
        expect(wrapper.text()).toContain('mer. 29 juil.')
        expect(wrapper.text()).toContain('4 exo')
    })

    it('donne un titre à la séance qui n’en a pas, jusque dans le libellé du bouton', () => {
        const wrapper = monter({ seance: { ...seance, name: null } })

        expect(wrapper.get('h4').text()).toBe('Séance')
        expect(wrapper.get('button').attributes('aria-label')).toBe('Supprimer la séance sans nom')
    })

    it('annonce trois exercices avec leurs séries, et compte ceux qu’elle tait', () => {
        const texte = monter().text()

        expect(texte).toContain('Développé')
        expect(texte).toContain('• 4 séries')
        expect(texte).toContain('Écarté')
        expect(texte).not.toContain('Pompes')
        expect(texte).toContain('+1')
    })

    it('n’annonce aucun exercice pour une séance vide', () => {
        const wrapper = monter({ seance: { ...seance, workout_lines: [] } })

        expect(wrapper.text()).toContain('0 exo')
        expect(wrapper.text()).not.toContain('séries')
    })

    it('ouvre la séance qu’elle nomme', () => {
        expect(monter().get('a').attributes('href')).toBe('/workouts.show/7')
    })

    it('demande la suppression à la page en nommant la séance, sans la décider elle-même', async () => {
        const wrapper = monter()

        await wrapper.get('[dusk="delete-workout-7"]').trigger('click')

        expect(wrapper.emitted('supprimer')).toEqual([[seance]])
    })
})
