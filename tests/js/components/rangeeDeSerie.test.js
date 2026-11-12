import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'
import DurationWheel from '@/Components/Workout/DurationWheel.vue'

const passesSlot = { template: '<div><slot /><slot name="action-right" /></div>' }

const monter = (props = {}) =>
    mount(RangeeDeSerie, {
        props: {
            set: { id: 9, weight: 80, reps: 5, is_completed: false, personal_record: null },
            index: 1,
            lineIndex: 2,
            line: { id: 1, exercise: { type: 'strength', name: 'Développé couché', default_rest_time: 120 } },
            ...props,
        },
        global: { stubs: { SwipeableRow: passesSlot }, directives: { press: {} } },
    })

describe('les mesures selon le type', () => {
    it('rend poids et répétitions pour la force, et relaie frappe et blur avec le champ', async () => {
        const wrapper = monter()

        await wrapper.get('[dusk="weight-input-2-1"]').setValue('85')
        await wrapper.get('[dusk="reps-input-2-1"]').trigger('change')

        expect(wrapper.emitted('saisie-en-cours')).toEqual([['weight', '85']])
        expect(wrapper.emitted('saisie-terminee')).toEqual([
            ['weight', '85'],
            ['reps', '5'],
        ])
    })

    it('rend distance et durée pour le cardio, et la durée seule pour le chronométré', async () => {
        const cardio = monter({
            line: { id: 1, exercise: { type: 'cardio', name: 'Course' } },
            set: { id: 9, distance_km: 5, duration_seconds: 1500 },
        })
        expect(cardio.find('[dusk="distance-input-2-1"]').exists()).toBe(true)
        expect(cardio.find('[dusk="weight-input-2-1"]').exists()).toBe(false)

        cardio.findComponent(DurationWheel).vm.$emit('update:modelValue', 1800)
        expect(cardio.emitted('update')).toEqual([['duration_seconds', 1800]])

        const chrono = monter({
            line: { id: 1, exercise: { type: 'timed', name: 'Planche' } },
            set: { id: 9, duration_seconds: 60 },
        })
        expect(chrono.findComponent(DurationWheel).exists()).toBe(true)
        expect(chrono.find('input').exists()).toBe(false)
    })
})

describe('la coche, le numéro et le retrait', () => {
    it('valide au clic, montre le trophée et dit si la série n’est pas enregistrée', async () => {
        const wrapper = monter({
            set: { id: 9, weight: 80, reps: 5, is_completed: true, personal_record: { id: 3 } },
            unsynced: true,
        })

        await wrapper.get('[dusk="complete-set-2-1"]').trigger('click')

        expect(wrapper.emitted('toggle')).toHaveLength(1)
        expect(wrapper.get('[dusk="complete-set-2-1"]').attributes('aria-label')).toContain('Annuler la série 2')
        expect(wrapper.find('[dusk="pr-trophy-2-1"]').exists()).toBe(true)
        expect(wrapper.get('[dusk="set-unsynced-2-1"]').attributes('aria-label')).toBe('Série 2 non enregistrée')
    })

    it('nomme la coche avec la série et l’exercice, et dit si elle est enfoncée', async () => {
        const ouverte = monter()
        const coche = ouverte.get('[dusk="complete-set-2-1"]')
        expect(coche.attributes('aria-label')).toBe('Valider la série 2, exercice Développé couché')
        expect(coche.attributes('aria-pressed')).toBe('false')

        const faite = monter({ set: { id: 9, weight: 80, reps: 5, is_completed: true, personal_record: { id: 3 } } })
        expect(faite.get('[dusk="complete-set-2-1"]').attributes('aria-label')).toBe(
            'Annuler la série 2, exercice Développé couché, record personnel',
        )
        expect(faite.get('[dusk="complete-set-2-1"]').attributes('aria-pressed')).toBe('true')
        expect(faite.get('[dusk="pr-trophy-2-1"] [role="img"]').attributes('aria-label')).toBe('Record personnel')
    })

    it('numérote le bouton de retrait visible dès sm comme celui du glissement', () => {
        const wrapper = monter()
        expect(wrapper.get('[dusk="remove-set-2-1"]').attributes('aria-label')).toBe('Supprimer la série 2')
        expect(wrapper.get('[dusk="swipe-remove-set-2-1"]').attributes('aria-label')).toBe('Supprimer la série 2')
    })

    it('estompe le fond d’une série faite, pas ses valeurs', () => {
        const faite = monter({ set: { id: 9, weight: 80, reps: 5, is_completed: true } })
        const rangee = faite.get('[data-poignee-serie], .carte-portable')
        expect(rangee.classes()).not.toContain('opacity-50')
        expect(rangee.classes()).toContain('bg-surface-card/40')
        expect(monter().get('.carte-portable').classes()).toContain('bg-surface-card/80')
    })

    it('fait du numéro une poignée du clavier quand la série peut bouger, un simple repère sinon', async () => {
        const mobile = monter({ reordonnable: true })
        const numero = mobile.get('[dusk="reorder-set-2-1"]')
        expect(numero.element.tagName).toBe('BUTTON')
        expect(numero.attributes('data-poignee-clavier')).toBe('')

        await numero.trigger('keydown', { key: 'ArrowUp' })
        await numero.trigger('keydown', { key: 'ArrowDown' })
        expect(mobile.emitted('deplacer')).toEqual([[0], [2]])

        const fixe = monter()
        expect(fixe.get('[dusk="reorder-set-2-1"]').element.tagName).toBe('DIV')
        expect(fixe.get('[dusk="reorder-set-2-1"]').attributes('data-poignee-clavier')).toBeUndefined()
    })

    it('retire par le bouton comme par l’action de glissement, sauf sur une séance close', async () => {
        const wrapper = monter()
        await wrapper.get('[dusk="remove-set-2-1"]').trigger('click')
        await wrapper.get('[dusk="swipe-remove-set-2-1"]').trigger('click')
        expect(wrapper.emitted('remove')).toHaveLength(2)

        const close = monter({ isFinished: true })
        expect(close.find('[dusk="remove-set-2-1"]').exists()).toBe(false)
        expect(close.get('[dusk="weight-input-2-1"]').attributes('disabled')).toBeDefined()
    })

    it('relaie l’appui du pointeur avec l’évènement natif, pour que la page écarte les commandes', async () => {
        const wrapper = monter({ reordonnable: true })

        await wrapper.get('[data-poignee-serie]').trigger('pointerdown')

        expect(wrapper.emitted('pointerdown')[0][0]).toBeInstanceOf(Event)
    })
})
