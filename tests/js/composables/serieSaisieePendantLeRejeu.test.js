import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { A_VENIR, retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    rechargerQuandLeReseauRevient,
    routeDeTest,
    seances,
    uneTentativeQuiTarde,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
    usePage: () => ({ props: {} }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * Le rejeu d'une création tarde, puis échoue sur une erreur réseau : ce que la
 * personne a saisi, coché ou supprimé pendant ce vol doit être dans la file
 * durable, pas seulement en mémoire (#2291).
 */

const seance = () => ({
    id: 5,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            recommended_values: null,
            sets: [{ id: 3, weight: 80, reps: 5, is_completed: true, is_warmup: false }],
        },
    ],
})

/** Une série ajoutée sans réseau, puis son rejeu qui vole : la page agit, puis le réseau retombe. */
const rejeuEnVol = async () => {
    reseau.serveur.enLigne = false
    const page = await monterLaSeance(seance())

    page.addSet(1)
    await flushPromises()
    const serie = page.ligne().sets.at(-1)

    reseau.serveur.enLigne = true
    const couper = uneTentativeQuiTarde(reseau.serveur)
    reseau.serveur.enLigne = true

    const vidage = page.sync.processQueue()
    await flushPromises()

    const coupure = async () => {
        await couper()
        await vidage
        await flushPromises()
    }

    return { page, serie, coupure }
}

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    globalThis.route = routeDeTest
})

afterEach(async () => {
    await seances.demonterToutes()
    vi.useRealTimers()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('une série dont la création est rejouée', () => {
    it('garde dans la file la saisie et la coche faites pendant le rejeu, quand il échoue sur le réseau', async () => {
        const { page, serie, coupure } = await rejeuEnVol()

        page.saisieTerminee(serie, 'reps', '3')
        page.toggleSetCompletion(serie)
        await flushPromises()
        await coupure()

        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
        ])

        await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 3, is_completed: true })
    })

    it('reste à annuler dans la file quand on la supprime pendant le rejeu, rechargement compris', async () => {
        const { page, serie, coupure } = await rejeuEnVol()

        page.removeSet(serie.id)
        await flushPromises()
        await coupure()

        expect(fileDurable()).toEqual([expect.objectContaining({ aAnnuler: `/api/v1/sets/${A_VENIR}` })])

        const rechargee = await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect([...reseau.serveur.series.keys()]).toEqual([3])
        expect(rechargee.queue).toEqual([])
    })

    it('garde dans la file la dernière coche, cochée pendant le rejeu puis décochée une fois celui-ci échoué', async () => {
        const { page, serie, coupure } = await rejeuEnVol()

        page.toggleSetCompletion(serie)
        await flushPromises()
        await coupure()
        page.toggleSetCompletion(serie)
        await flushPromises()

        expect(serie.is_completed).toBe(false)
        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: false, weight: 80, reps: 5 },
        ])

        await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.series.get(100)).toMatchObject({ is_completed: false })
    })

    it('quitte la file avec ses séries quand son exercice est retiré pendant le rejeu, rechargement compris', async () => {
        reseau.serveur.enLigne = false
        const page = await monterLaSeance({ id: 5, workout_lines: [] })

        page.addExercise(7)
        const ligne = page.ligne()

        page.addSet(ligne.id)
        await flushPromises()

        reseau.serveur.enLigne = true
        const couper = uneTentativeQuiTarde(reseau.serveur)
        const vidage = page.sync.processQueue()
        await flushPromises()

        page.removeLine(ligne.id)
        page.confirmerLeRetrait()
        await flushPromises()
        await couper()
        await vidage
        await flushPromises()

        expect(fileDurable()).toEqual([
            expect.objectContaining({ url: '/api/v1/workout-lines', aAnnuler: `/api/v1/workout-lines/${A_VENIR}` }),
        ])

        const rechargee = await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.lignes.size).toBe(0)
        expect(reseau.serveur.series.size).toBe(1)
        expect(rechargee.queue).toEqual([])
    })
})
