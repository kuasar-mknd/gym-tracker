import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { chargerSyncService, retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, fileDurable, monterLaSeance, routeDeTest } from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * Les séries d'un exercice lui-même ajouté hors ligne n'existaient qu'en
 * mémoire : leur création attendait que le vidage annonce l'identifiant de
 * l'exercice. Un rechargement, la PWA arrêtée pendant que le téléphone dormait
 * ou le démontage de la page les perdaient, et le vidage créait un exercice
 * vide (#1962).
 */

const seanceVide = () => ({ id: 5, workout_lines: [] })

/** Sans réseau : l'exercice 7, puis trois séries saisies. */
const unExerciceEtTroisSeriesHorsLigne = async (page) => {
    reseau.serveur.enLigne = false

    page.addExercise(7)
    const ligne = page.ligne()

    page.addSet(ligne.id)
    page.addSet(ligne.id)
    page.addSet(ligne.id)
    await flushPromises()

    const [premiere, deuxieme, troisieme] = ligne.sets
    page.saisieTerminee(premiere, 'weight', '60')
    page.saisieTerminee(deuxieme, 'weight', '62.5')
    page.saisieTerminee(troisieme, 'reps', '8')
    page.toggleSetCompletion(premiere)
    await flushPromises()

    return ligne
}

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur()
    globalThis.route = routeDeTest
})

afterEach(() => {
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('les séries d’un exercice encore en file', () => {
    it('sont dans la file persistée, derrière leur exercice, avec ce qui a été saisi', async () => {
        const page = await monterLaSeance(seanceVide())

        await unExerciceEtTroisSeriesHorsLigne(page)

        const file = fileDurable()
        const ligne = file[0]

        expect(file.map((entree) => `${entree.method} ${entree.url}`)).toEqual([
            'post /api/v1/workout-lines',
            'post /api/v1/sets',
            'post /api/v1/sets',
            'post /api/v1/sets',
        ])
        expect(file.slice(1).map((entree) => entree.data)).toEqual([
            { workout_line_id: { enAttenteDe: ligne.id }, is_completed: true, weight: 60, reps: 10 },
            { workout_line_id: { enAttenteDe: ligne.id }, is_completed: false, weight: 62.5, reps: 10 },
            // Copiée de la deuxième au moment de l'ajout, avant qu'on la corrige.
            { workout_line_id: { enAttenteDe: ligne.id }, is_completed: false, weight: 0, reps: 8 },
        ])
    })

    it('arrivent au serveur avec leur exercice après un rechargement, avec les valeurs saisies', async () => {
        const page = await monterLaSeance(seanceVide())
        await unExerciceEtTroisSeriesHorsLigne(page)

        // La page meurt ; un nouveau chargement relit la file, et le réseau revient.
        page.wrapper.unmount()
        reseau.serveur.enLigne = true
        const rechargee = await chargerSyncService({ compte: 1 })
        await rechargee.pending

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/workout-lines {"workout_id":5,"exercise_id":7}',
            'post /api/v1/sets {"workout_line_id":70,"is_completed":true,"weight":60,"reps":10}',
            'post /api/v1/sets {"workout_line_id":70,"is_completed":false,"weight":62.5,"reps":10}',
            'post /api/v1/sets {"workout_line_id":70,"is_completed":false,"weight":0,"reps":8}',
        ])
        expect([...reseau.serveur.series.values()].map((serie) => serie.workout_line_id)).toEqual([70, 70, 70])
        expect(rechargee.queue).toEqual([])
    })

    it('gardent leur exercice quand le rechargement tombe entre le rejeu de l’exercice et celui des séries', async () => {
        const page = await monterLaSeance(seanceVide())
        await unExerciceEtTroisSeriesHorsLigne(page)
        page.wrapper.unmount()

        // Le réseau ne revient que le temps de créer l'exercice.
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(async (config) => {
            reseau.serveur.enLigne = false

            return { data: { data: { id: 70, ...config.data } } }
        })
        const premiere = await chargerSyncService({ compte: 1 })
        await premiere.pending

        expect(fileDurable().map((entree) => entree.data.workout_line_id)).toEqual([70, 70, 70])

        reseau.serveur.enLigne = true
        const seconde = await chargerSyncService({ compte: 1 })
        await seconde.pending

        expect(reseau.serveur.resume().filter((requete) => requete.startsWith('post /api/v1/sets'))).toHaveLength(3)
        expect(seconde.queue).toEqual([])
    })

    it('prennent leur identifiant sans rechargement, quand la page est encore là au vidage', async () => {
        const page = await monterLaSeance(seanceVide())
        const ligne = await unExerciceEtTroisSeriesHorsLigne(page)

        reseau.serveur.enLigne = true
        await page.sync.processQueue()
        await flushPromises()

        expect(ligne.id).toBe(70)
        expect(ligne.sets.map((serie) => serie.id)).toEqual([100, 101, 102])
        expect(page.unsyncedSetIds.value.size).toBe(0)

        // Un rafraîchissement des props montre l'exercice une fois, avec ses trois séries.
        page.rafraichir({
            ...seanceVide(),
            workout_lines: [{ ...reseau.serveur.lignes.get(70), sets: [...reseau.serveur.series.values()] }],
        })

        expect(page.localWorkout.value.workout_lines.map((l) => l.id)).toEqual([70])
        expect(page.ligne().sets.map((serie) => serie.id)).toEqual([100, 101, 102])
    })

    it('quittent la file avec leur exercice quand il est retiré avant le retour du réseau', async () => {
        const page = await monterLaSeance(seanceVide())
        const ligne = await unExerciceEtTroisSeriesHorsLigne(page)

        page.removeLine(ligne.id)
        page.confirmerLeRetrait()
        await flushPromises()

        expect(fileDurable()).toEqual([])

        reseau.serveur.enLigne = true
        await page.sync.processQueue()

        expect(reseau.serveur.resume()).toEqual([])
    })

    it('sont refusées sans partir, et annoncées, quand le serveur refuse leur exercice', async () => {
        const page = await monterLaSeance(seanceVide())
        await unExerciceEtTroisSeriesHorsLigne(page)
        const refus = vi.fn()
        window.addEventListener('sync:failed', refus)
        reseau.serveur.imposer.push(() => Promise.reject({ response: { status: 422 } }))

        reseau.serveur.enLigne = true
        await page.sync.processQueue()

        window.removeEventListener('sync:failed', refus)

        expect(reseau.serveur.resume()).toEqual(['post /api/v1/workout-lines {"workout_id":5,"exercise_id":7}'])
        expect(refus).toHaveBeenCalledTimes(4)
        expect(page.sync.queue).toEqual([])
    })
})
