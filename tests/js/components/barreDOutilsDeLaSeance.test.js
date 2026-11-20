import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import { noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'

/*
 * La barre d'outils de la séance (#2434) : sur téléphone, Exercice, Repos et
 * Terminer flottent au-dessus de la barre du bas ; sur bureau, les mêmes
 * boutons rejoignent la colonne.
 */
describe('la barre d’outils de la séance', () => {
    beforeEach(() => noterQueLeServeurRepond())

    const barre = () =>
        mount(ActionsDeLaSeance, { global: { directives: { press: {} } } }).get('[dusk="barre-d-outils-de-la-seance"]')

    it('porte Exercice, Repos et Terminer, avec les mêmes gestes que la colonne de bureau', async () => {
        const wrapper = mount(ActionsDeLaSeance, { global: { directives: { press: {} } } })
        const groupe = wrapper.get('[dusk="barre-d-outils-de-la-seance"]')

        expect(groupe.attributes('role')).toBe('group')
        expect(groupe.attributes('aria-label')).toBe('Actions de la séance')
        expect(groupe.findAll('button').map((bouton) => bouton.attributes('dusk'))).toEqual([
            'add-exercise-existing',
            'open-rest-timer',
            'finish-workout-mobile',
        ])

        await groupe.get('[dusk="add-exercise-existing"]').trigger('click')
        await groupe.get('[dusk="open-rest-timer"]').trigger('click')
        await groupe.get('[dusk="finish-workout-mobile"]').trigger('click')

        expect(Object.keys(wrapper.emitted())).toEqual(
            expect.arrayContaining(['ajouter-un-exercice', 'demarrer-un-repos', 'terminer']),
        )
    })

    it('laisse « Modèle » hors de la barre, dans la colonne', () => {
        const wrapper = mount(ActionsDeLaSeance, { global: { directives: { press: {} } } })

        expect(barre().find('[dusk="enregistrer-comme-modele"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="enregistrer-comme-modele"]').exists()).toBe(true)
    })

    it('dit « Exercice » et « Repos » sur téléphone, et leur phrase entière sur bureau', () => {
        const textes = barre()
            .findAll('button span:not(.material-symbols-outlined)')
            .map((span) => `${span.text()}|${span.classes().join(' ')}`)

        expect(textes).toEqual([
            'Exercice|bureau:hidden',
            'Ajouter un exercice|bureau:inline hidden',
            'Repos|bureau:hidden',
            'Démarrer un repos|bureau:inline hidden',
        ])
    })

    it('flotte au-dessus de la zone sûre et de la barre du bas, hors de la colonne de bureau', () => {
        const classes = barre().classes()

        expect(classes).toEqual(expect.arrayContaining(['fixed', 'bottom-sure-30', 'left-sure-4', 'right-sure-4']))
        expect(classes).toContain('bureau:contents')
        expect(classes).toContain('verre-epais')
    })
})
