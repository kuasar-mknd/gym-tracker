import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    rechargerQuandLeReseauRevient,
    routeDeTest,
    uneReponsePerdue,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * La création atteint le serveur, qui l'enregistre, puis la réponse se perd en
 * route : un réseau faible, ou un 502 du proxy après traitement. La page ne
 * voit qu'une erreur réseau et met la création en file, avec la même clé
 * d'idempotence. Au rejeu, le serveur reconnaît la clé et rend la ligne qu'il
 * avait créée, telle qu'elle est, sans lire la charge rejouée.
 *
 * Tout le correctif de #1960 supposait qu'une entrée en file n'avait encore
 * rien produit : la saisie et la coche fondues dans l'entrée semblaient
 * arrivées alors que le serveur les avait ignorées, et une suppression faite
 * pendant l'attente ne retirait que l'entrée, laissant la ligne en base.
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

/** Ajoute une série dont la création atteint le serveur et dont la réponse se perd. */
const ajouterSansReponse = async (page) => {
    uneReponsePerdue(reseau.serveur)
    page.addSet(1)
    await flushPromises()

    return page.ligne().sets.at(-1)
}

/** Le réseau revient : la file se vide, et ce qui en découle part derrière. */
const leReseauRevient = async (page) => {
    reseau.serveur.enLigne = true
    await page.sync.processQueue()
    await flushPromises()
    await page.sync.pending
    await flushPromises()
}

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    globalThis.route = routeDeTest
})

afterEach(() => {
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('une création dont la réponse s’est perdue', () => {
    it('envoie ensuite ce que le serveur a ignoré : la saisie et la coche faites pendant l’attente', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterSansReponse(page)

        // Le serveur tient déjà la série, recopiée de la précédente et décochée.
        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 5, is_completed: false })

        page.saisieTerminee(serie, 'reps', '3')
        await page.toggleSetCompletion(serie)
        await flushPromises()

        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
        ])

        await leReseauRevient(page)

        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 3, is_completed: true })
        expect(serie).toMatchObject({ id: 100, reps: 3, is_completed: true })
        expect(page.unsyncedSetIds.value.size).toBe(0)
        expect(page.sync.queue).toEqual([])
    })

    it('n’envoie rien de plus quand le serveur a pris ce qui est parti', async () => {
        const page = await monterLaSeance(seance())
        reseau.serveur.enLigne = false
        page.addSet(1)
        await flushPromises()
        const serie = page.ligne().sets.at(-1)

        page.saisieTerminee(serie, 'reps', '3')
        await page.toggleSetCompletion(serie)
        await flushPromises()

        await leReseauRevient(page)

        // La première tentative n'a pas atteint le serveur : le rejeu crée la série telle qu'elle est.
        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":1,"is_completed":true,"weight":80,"reps":3}',
        ])
        expect(serie).toMatchObject({ id: 100, reps: 3, is_completed: true })
    })

    it('supprime du serveur la série retirée pendant l’attente', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterSansReponse(page)

        page.removeSet(serie.id)
        await flushPromises()

        expect(page.ligne().sets.map((s) => s.id)).toEqual([3])

        await leReseauRevient(page)

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/sets',
            'post /api/v1/sets',
            'delete /api/v1/sets/100',
        ])
        expect([...reseau.serveur.series.keys()]).toEqual([3])
        expect(page.ligne().sets.map((s) => s.id)).toEqual([3])
        expect(page.sync.queue).toEqual([])
    })

    it('supprime du serveur l’exercice retiré pendant l’attente, rechargement compris', async () => {
        const page = await monterLaSeance(seance())
        uneReponsePerdue(reseau.serveur)
        page.addExercise(7)
        await flushPromises()

        // La ligne existe sur le serveur ; la page ne le sait pas.
        expect([...reseau.serveur.lignes.keys()]).toEqual([70])

        page.removeLine(page.ligne(1).id)
        page.confirmerLeRetrait()
        await flushPromises()

        expect(fileDurable()).toEqual([
            expect.objectContaining({ url: '/api/v1/workout-lines', aAnnuler: '/api/v1/workout-lines/__produit__' }),
        ])

        const rechargee = await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/workout-lines',
            'post /api/v1/workout-lines',
            'delete /api/v1/workout-lines/70',
        ])
        expect(reseau.serveur.lignes.size).toBe(0)
        expect(rechargee.queue).toEqual([])
    })
})
