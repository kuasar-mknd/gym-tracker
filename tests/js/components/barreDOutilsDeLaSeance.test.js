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

        expect(classes).toEqual(
            expect.arrayContaining(['fixed', 'mb-0', 'bottom-sure-24', 'left-sure-4', 'right-sure-4']),
        )
        expect(classes).toContain('bureau:contents')
        expect(classes).toContain('verre-epais')
    })

    /*
     * Sans le bouton central, une séance sans exercice n'avait plus de chemin
     * vers sa fin : la barre reste, réduite à Exercice et Terminer.
     */
    it('reste sur une séance vide, avec Exercice et Terminer seulement', async () => {
        const wrapper = mount(ActionsDeLaSeance, { props: { vide: true }, global: { directives: { press: {} } } })
        const groupe = wrapper.get('[dusk="barre-d-outils-de-la-seance"]')

        expect(groupe.findAll('button').map((bouton) => bouton.attributes('dusk'))).toEqual([
            'add-exercise-existing',
            'finish-workout-mobile',
        ])
        expect(wrapper.find('[dusk="enregistrer-comme-modele"]').exists()).toBe(false)

        await groupe.get('[dusk="finish-workout-mobile"]').trigger('click')

        expect(wrapper.emitted('terminer')).toHaveLength(1)
    })

    it('donne à Terminer l’orange de la charte, en casse de phrase', () => {
        const terminer = barre().get('[dusk="finish-workout-mobile"]')

        expect(terminer.classes()).toEqual(expect.arrayContaining(['accent-fill', 'bouton-de-barre']))
        expect(terminer.classes()).not.toContain('glass-button-primary')
    })

    /*
     * Une séance démarrée sans réseau s'ouvre à l'adresse de sa clé (#2049) : le
     * bouton central y démarrait une seconde séance au lieu d'ajouter un exercice.
     * La barre d'outils, elle, ajoute l'exercice à celle-ci, même hors ligne.
     */
    it('ajoute un exercice à une séance démarrée sans réseau, sans la raison d’un bouton fermé', async () => {
        const wrapper = mount(ActionsDeLaSeance, {
            props: { seanceEnAttente: true },
            global: { directives: { press: {} } },
        })
        const exercice = wrapper.get('[dusk="add-exercise-existing"]')

        expect(exercice.attributes('disabled')).toBeUndefined()
        expect(exercice.attributes('aria-describedby')).toBeUndefined()

        await exercice.trigger('click')

        expect(wrapper.emitted('ajouter-un-exercice')).toHaveLength(1)
    })
})
