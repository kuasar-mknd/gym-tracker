import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { h } from 'vue'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page n'a rien à montrer : la séance et sa question de fin, la vraie, comptent seules.
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_props, { slots }) =>
                () =>
                    rendre('div', [slots['header-actions']?.(), slots.default?.()]),
        },
    }
})
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))

/*
 * Terminer une séance oubliée (#2319), avec le vrai client d'Inertia, la vraie
 * page de séance, sa vraie question de fin et la vraie file hors ligne : la
 * séance est ouverte depuis plus de douze heures, la question propose l'heure
 * de la dernière série saisie, et la fin part avec cette heure, `fin_choisie`,
 * à côté de l'heure du geste.
 */

const pages = suivreLesPagesMontees()

const DEBUT = '2026-10-12T17:00:00.000Z'
const DERNIERE_SERIE = '2026-10-12T18:30:00.000Z'

const Accueil = { name: 'Accueil', render: () => h('div', { id: 'accueil' }) }

let visites
let visiter

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true, updated_at: DERNIERE_SERIE }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    reseau.serveur.debutDeLaSeance = DEBUT

    ;({ visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        composants: { Dashboard: Accueil },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    vi.useRealTimers()
    await fermerLaListeDesSeances()
})

it('propose l’heure de la dernière série saisie, et clôt la séance avec elle', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    visites.length = 0

    await cliquer('[dusk="finish-workout-mobile"]')
    await vi.waitUntil(() => present('dialog[open] [dusk="confirm-finish-button"]'))

    expect(present('[dusk="finish-workout-forgotten"]')).toBe(true)
    expect(document.querySelector('[dusk="finish-workout-proposed"]').textContent).toContain(
        new Date(DERNIERE_SERIE).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }),
    )

    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-19T06:42:00.000Z'))
    await cliquer('[dusk="confirm-finish-button"]')
    await vi.waitUntil(() => reseau.serveur.requetes.length > 0)
    vi.useRealTimers()
    // La fin acceptée ouvre le bilan sur la page, sans visite ; « Fermer » mène à l'accueil (#2434).
    await vi.waitUntil(() => present('dialog[open] [dusk="bilan-de-fin"]'))
    expect(visites).toEqual([])
    await cliquer('[dusk="bilan-de-fin-fermer"]')
    await vi.waitUntil(() => present('#accueil'))
    await flushPromises()

    expect(reseau.serveur.resume()).toEqual([
        `patch /api/v1/workouts/finish {"workout_id":5,"ended_at":"2026-10-19T06:42:00.000Z","fin_choisie":"${DERNIERE_SERIE}"}`,
        'get /workouts/5/records',
    ])
    expect(visites).toEqual(['/dashboard'])
    expect(fileDurable()).toEqual([])

    await historiqueAuRepos()
})
