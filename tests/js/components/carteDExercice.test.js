import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'

const passesSlot = { template: '<div><slot /></div>' }

const ligne = () => ({
    id: 1,
    exercise: { type: 'strength', name: 'Développé couché', category: 'Pectoraux', default_rest_time: 120 },
    sets: [
        { id: 11, weight: 80, reps: 5, is_completed: false },
        { id: 12, weight: 80, reps: 5, is_completed: false },
    ],
})

const monter = (props = {}) =>
    mount(CarteDExercice, {
        props: {
            line: ligne(),
            lineIndex: 0,
            clef: (rangee) => rangee.id,
            estNonSynchronisee: (set) => set.id === 12,
            ...props,
        },
        global: { stubs: { GlassCard: passesSlot, SwipeableRow: passesSlot }, directives: { press: {} } },
    })

describe('la carte d’un exercice', () => {
    it('nomme l’exercice, rend ses séries et dit laquelle n’est pas enregistrée', () => {
        const wrapper = monter()

        expect(wrapper.text()).toContain('Développé couché')
        expect(wrapper.text()).toContain('Pectoraux')
        expect(wrapper.findAllComponents(RangeeDeSerie)).toHaveLength(2)
        expect(wrapper.find('[dusk="set-unsynced-0-0"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(true)
    })

    it('ne montre la poignée qu’avec un autre exercice à dépasser, et la fait marcher au clavier', async () => {
        const seul = monter()
        expect(seul.find('[dusk="reorder-line-0"]').exists()).toBe(false)

        const accompagne = monter({ deplacable: true, lineIndex: 1 })
        const poignee = accompagne.get('[dusk="reorder-line-1"]')
        expect(poignee.attributes('data-poignee-exercice')).toBe('')

        await poignee.trigger('keydown', { key: 'ArrowUp' })
        await poignee.trigger('keydown', { key: 'ArrowDown' })
        expect(accompagne.emitted('deplacer')).toEqual([[0], [2]])

        const close = monter({ deplacable: true, isFinished: true })
        expect(close.find('[dusk="reorder-line-0"]').exists()).toBe(false)
        expect(close.find('[dusk="add-set-0"]').exists()).toBe(false)
    })

    it('demande le retrait de l’exercice et l’ajout d’une série', async () => {
        const wrapper = monter()

        await wrapper.get('[dusk="remove-line-0"]').trigger('click')
        await wrapper.get('[dusk="add-set-0"]').trigger('click')

        expect(wrapper.emitted('retirer')).toHaveLength(1)
        expect(wrapper.emitted('ajouter-serie')).toHaveLength(1)
    })

    it('relaie ce que la rangée dit, en nommant la série', async () => {
        const wrapper = monter({ reordonnable: true })
        const [premiere, seconde] = wrapper.findAllComponents(RangeeDeSerie)

        await premiere.get('[dusk="complete-set-0-0"]').trigger('click')
        await seconde.get('[dusk="weight-input-0-1"]').setValue('90')
        await seconde.get('[dusk="reorder-set-0-1"]').trigger('keydown', { key: 'ArrowUp' })
        seconde.vm.$emit('remove')

        expect(wrapper.emitted('toggle')).toEqual([[ligne().sets[0]]])
        expect(wrapper.emitted('saisie-en-cours')).toEqual([[ligne().sets[1], 'weight', '90']])
        expect(wrapper.emitted('deplacer-serie')).toEqual([[1, 0]])
        expect(wrapper.emitted('remove')).toEqual([[ligne().sets[1]]])
    })

    it('donne le conteneur des séries à la page, qui y branche le glissement', () => {
        const poserLeConteneur = vi.fn()
        monter({ poserLeConteneur })

        expect(poserLeConteneur).toHaveBeenCalledWith(expect.any(HTMLElement), expect.anything())
    })
})
