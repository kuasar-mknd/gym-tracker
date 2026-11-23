import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import { formatToLocalISO } from '@/Utils/date'

/*
 * La question « Terminer la séance ? » d'une séance oubliée (#2319) : ouverte
 * depuis plus de douze heures, elle propose l'heure de la dernière série
 * saisie, ou une heure à choisir, entre le début et maintenant.
 */
const MAINTENANT = new Date('2026-10-19T12:00:00.000Z')
const DEBUT = '2026-10-18T07:00:00.000Z'
const DERNIERE_SERIE = '2026-10-18T09:30:00.000Z'

const monter = (props = {}) =>
    mount(WorkoutFinishModal, {
        props: { show: true, ...props },
        global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
    })

const SEANCE_OUBLIEE = {
    started_at: DEBUT,
    workout_lines: [
        { sets: [{ updated_at: '2026-10-18T08:00:00.000Z' }] },
        { sets: [{ created_at: '2026-10-18T09:00:00.000Z', updated_at: DERNIERE_SERIE }] },
    ],
}

const oubliee = () => monter({ seance: SEANCE_OUBLIEE })

const confirmer = (wrapper) => wrapper.get('[dusk="confirm-finish-button"]').trigger('click')

const choisirUneAutreHeure = async (wrapper) => {
    await wrapper
        .findAll('button')
        .find((bouton) => bouton.text() === 'Autre heure')
        .trigger('click')
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(MAINTENANT)
})

afterEach(() => {
    vi.useRealTimers()
})

describe('une séance de moins de douze heures', () => {
    it('ne propose aucune heure, et confirme sans rien porter', async () => {
        const wrapper = monter({ seance: { started_at: '2026-10-19T00:30:00.000Z', workout_lines: [] } })

        expect(wrapper.find('[dusk="finish-workout-forgotten"]').exists()).toBe(false)

        await confirmer(wrapper)

        expect(wrapper.emitted('confirm')).toEqual([[]])
    })
})

describe('une séance oubliée', () => {
    it('propose de terminer à l’heure de la dernière série saisie, et confirme avec cette heure', async () => {
        const wrapper = oubliee()

        expect(wrapper.get('[dusk="finish-workout-forgotten"]').text()).toContain('Cette séance est ouverte depuis le')
        expect(wrapper.get('[dusk="finish-workout-proposed"]').text()).toMatch(
            /^Dernière série : \d{2}\/\d{2} à \d{2}:\d{2}$/,
        )

        await confirmer(wrapper)

        expect(wrapper.emitted('confirm')).toEqual([[DERNIERE_SERIE]])
    })

    it('propose l’heure de démarrage, et le dit, quand aucune série n’a été saisie', async () => {
        const wrapper = monter({ seance: { started_at: DEBUT, workout_lines: [] } })

        expect(wrapper.get('[dusk="finish-workout-proposed"]').text()).toContain('Démarrage')
        expect(wrapper.text()).not.toContain('dernière série')

        await confirmer(wrapper)

        expect(wrapper.emitted('confirm')).toEqual([[DEBUT]])
    })

    it('ne précède jamais le début quand la minute choisie est celle du début, qui a des secondes', async () => {
        const debutAvecSecondes = '2026-10-18T07:00:42.000Z'
        const wrapper = monter({ seance: { started_at: debutAvecSecondes, workout_lines: [] } })
        await choisirUneAutreHeure(wrapper)

        // La minute du début, sans ses secondes : ce que le champ sait écrire.
        await wrapper.get('[dusk="finish-workout-end-input"]').setValue(formatToLocalISO(debutAvecSecondes))
        await confirmer(wrapper)

        expect(wrapper.emitted('confirm')).toEqual([[debutAvecSecondes]])
    })

    it('laisse choisir l’heure à la main, entre le début et maintenant, et confirme avec elle', async () => {
        const wrapper = oubliee()
        await choisirUneAutreHeure(wrapper)

        const champ = wrapper.get('[dusk="finish-workout-end-input"]')

        expect(champ.attributes('min')).toBe(formatToLocalISO(DEBUT))
        expect(champ.attributes('max')).toBe(formatToLocalISO(MAINTENANT))
        // Le champ s'ouvre sur la proposition.
        expect(champ.element.value).toBe(formatToLocalISO(DERNIERE_SERIE))

        await champ.setValue('2026-10-18T14:15')
        await confirmer(wrapper)

        expect(wrapper.emitted('confirm')).toEqual([[new Date('2026-10-18T14:15').toISOString()]])
    })

    it('refuse une heure d’avant le début, sans rien confirmer', async () => {
        const wrapper = oubliee()
        await choisirUneAutreHeure(wrapper)

        await wrapper.get('[dusk="finish-workout-end-input"]').setValue('2026-10-18T06:00')
        await confirmer(wrapper)

        expect(wrapper.text()).toContain('La fin ne peut pas précéder le début de la séance.')
        expect(wrapper.get('[dusk="confirm-finish-button"]').attributes('disabled')).toBeDefined()
        expect(wrapper.emitted('confirm')).toBeUndefined()
    })

    it('refuse une heure à venir, sans rien confirmer', async () => {
        const wrapper = oubliee()
        await choisirUneAutreHeure(wrapper)

        await wrapper.get('[dusk="finish-workout-end-input"]').setValue('2026-10-20T08:00')
        await confirmer(wrapper)

        expect(wrapper.text()).toContain('La fin ne peut pas être dans le futur.')
        expect(wrapper.emitted('confirm')).toBeUndefined()
    })

    it('refuse un champ vidé', async () => {
        const wrapper = oubliee()
        await choisirUneAutreHeure(wrapper)

        await wrapper.get('[dusk="finish-workout-end-input"]').setValue('')

        expect(wrapper.text()).toContain('Choisis une date et une heure.')
        expect(wrapper.get('[dusk="confirm-finish-button"]').attributes('disabled')).toBeDefined()
    })

    it('revient à la proposition quand on rouvre la question', async () => {
        const wrapper = oubliee()
        await choisirUneAutreHeure(wrapper)
        await wrapper.get('[dusk="finish-workout-end-input"]').setValue('2026-10-18T14:15')

        await wrapper.setProps({ show: false })
        await wrapper.setProps({ show: true })

        expect(wrapper.find('[dusk="finish-workout-end-input"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="finish-workout-proposed"]').exists()).toBe(true)
    })
})
