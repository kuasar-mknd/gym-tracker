import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'

/*
 * Le bilan de fin de séance (#2434) : la question « Terminer ? » dit ce que la
 * séance a donné (durée, volume, séries validées) avant que rien soit
 * enregistré. « Enregistrer » est la confirmation d'avant, « Reprendre » laisse
 * la séance ouverte : rien ne la rouvre une fois enregistrée.
 */
const MAINTENANT = new Date('2026-11-19T18:00:00.000Z')
const DEBUT = '2026-11-19T17:06:00.000Z'

const SEANCE = {
    started_at: DEBUT,
    workout_lines: [
        {
            sets: [
                { is_completed: true, weight: '82.50', reps: 8 },
                { is_completed: true, weight: 82.5, reps: 8, is_warmup: false },
                { is_completed: false, weight: 90, reps: 5 },
            ],
        },
        { sets: [{ is_completed: true, weight: null, reps: 12 }] },
    ],
}

const monter = (props = {}) =>
    mount(WorkoutFinishModal, {
        props: { show: true, seance: SEANCE, ...props },
        global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
    })

const lire = (wrapper, dusk) => wrapper.get(`[dusk="${dusk}"]`).text()

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(MAINTENANT)
})

afterEach(() => vi.useRealTimers())

describe('le bilan dans la question « Terminer la séance ? »', () => {
    it('dit la durée, le volume des séries validées et leur nombre', () => {
        const wrapper = monter()

        expect(lire(wrapper, 'finish-workout-duree')).toBe('54 min')
        // 82,5 × 8 deux fois ; la série décochée et celle sans poids ne comptent pas.
        expect(lire(wrapper, 'finish-workout-volume').replace(/\s/g, ' ')).toMatch(/^1\D?320 kg$/)
        expect(lire(wrapper, 'finish-workout-series')).toBe('3')
    })

    it('écrit une durée d’une heure ou plus en heures et minutes', () => {
        const wrapper = monter({ seance: { ...SEANCE, started_at: '2026-11-19T16:56:00.000Z' } })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('1 h 04')
    })

    it('ne compte aucun volume ni série pour une séance sans série cochée', () => {
        const wrapper = monter({ seance: { started_at: DEBUT, workout_lines: [{ sets: [{ is_completed: false }] }] } })

        expect(lire(wrapper, 'finish-workout-volume')).toBe('0 kg')
        expect(lire(wrapper, 'finish-workout-series')).toBe('0')
    })

    it('tient sans séance', () => {
        const wrapper = monter({ seance: null })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('—')
        expect(lire(wrapper, 'finish-workout-series')).toBe('0')
    })

    it('mesure une séance oubliée à l’heure de fin retenue, pas à maintenant', () => {
        const wrapper = monter({
            seance: {
                started_at: '2026-11-17T09:00:00.000Z',
                workout_lines: [
                    { sets: [{ is_completed: true, weight: 50, reps: 10, updated_at: '2026-11-17T10:30:00.000Z' }] },
                ],
            },
        })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('1 h 30')
    })

    it('nomme « Enregistrer » la confirmation et « Reprendre » le retour, sans rien annoncer des records', async () => {
        const wrapper = monter()
        const boutons = wrapper.findAll('button').map((bouton) => bouton.text())

        expect(boutons).toEqual(['Reprendre', 'Enregistrer'])
        expect(wrapper.text()).toContain('Une séance enregistrée ne se rouvre plus.')
        expect(wrapper.text()).not.toMatch(/record/i)

        await wrapper.get('[dusk="confirm-finish-button"]').trigger('click')
        expect(wrapper.emitted('confirm')).toEqual([[]])
        expect(wrapper.emitted('close')).toBeUndefined()

        await wrapper.findAll('button')[0].trigger('click')
        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('ne dit rien du réseau avant l’appui', () => {
        const wrapper = monter()

        expect(wrapper.text()).not.toMatch(/réseau|appareil|hors ligne/i)
    })
})
