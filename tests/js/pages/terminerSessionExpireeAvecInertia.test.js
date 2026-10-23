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
import { FIN_NON_ENREGISTREE } from '@/Utils/seanceEnAttente'

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
 * « Terminer » quand la session a expiré (#2049), avec le vrai client
 * d'Inertia, la vraie page de séance, sa vraie question de fin et la vraie
 * file hors ligne.
 *
 * Avant #2049, la fin passait par une visite d'Inertia (`workouts.update`),
 * que le serveur renvoyait à la connexion. Elle passe par l'API, qui répond
 * 401 (ou 419, le jeton CSRF de la session expirée) : la page disait « La fin
 * de la séance n'a pas été enregistrée : elle reste ouverte. » à chaque
 * essai, sans chemin vers la connexion. Désormais, la porte fermée n'est pas
 * un refus : la fin attend en file, à l'heure du geste, la page se redemande
 * au serveur, qui mène à la connexion, et la fin repart une fois la personne
 * reconnectée.
 *
 * Le faux serveur répond 401 à toute écriture tant que la session est
 * expirée, rejeux de la file compris, et sa page de la séance devient celle
 * de la connexion, comme la redirection du serveur que le client d'Inertia
 * suit sans la voir.
 */

const pages = suivreLesPagesMontees()

/**
 * L'heure du geste : une heure avant le chargement du fichier, à la minute.
 * La fin mise en file la garde après le retour de l'horloge réelle, et la file
 * abandonne au vidage ce qui attend depuis plus de trente jours : écrite en
 * dur, cette heure faisait tomber le test trente jours après (#2123).
 */
const HEURE_DU_GESTE = new Date(Math.floor((Date.now() - 3_600_000) / 60_000) * 60_000)

/** La page de connexion, celle que le serveur rend à une session expirée. */
const pageDeConnexion = () => ({
    component: 'Auth/Login',
    url: '/login',
    version: 'v1',
    props: { errors: {}, auth: { user: null } },
    flash: {},
    encryptHistory: false,
})

const Connexion = { name: 'Connexion', render: () => h('p', { id: 'connexion' }, 'Connexion') }

let visites
let visiter
let usePage

/** Tant qu'elle est vraie, le serveur ferme la porte : 401 à chaque écriture. */
let sessionExpiree = false

/** Les écritures que la porte fermée a refusées. */
const refusees = []

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    ;({ visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: (inertia) => {
            usePage = inertia.usePage
        },
        composants: { 'Auth/Login': Connexion },
    }))

    const repondre = reseau.serveur.repondre

    reseau.serveur.repondre = async (config) => {
        if (sessionExpiree) {
            refusees.push(`${config.method} ${config.url}`)

            throw { response: { status: 401, data: { message: 'Unauthenticated.' } } }
        }

        return repondre(config)
    }
})

afterEach(async () => {
    await pages.demonterToutes()
    vi.useRealTimers()
    await fermerLaListeDesSeances()
})

it('met la fin en file à l’heure du geste, mène à la connexion, et termine la séance une fois reconnecté', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    visites.length = 0

    // La session expire : le serveur ferme la porte, et sa page de la séance mène à la connexion.
    sessionExpiree = true
    reseau.serveur.pages = { '/workouts/5': pageDeConnexion }

    // « Terminer », puis « Confirmer » à l'heure du geste.
    await cliquer('[dusk="finish-workout-mobile"]')
    await vi.waitUntil(() => present('dialog[open] [dusk="confirm-finish-button"]'))
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(HEURE_DU_GESTE)
    await cliquer('[dusk="confirm-finish-button"]')
    await vi.waitUntil(() => fileDurable().length === 1)
    vi.useRealTimers()

    // La page s'est redemandée au serveur, qui a mené à la connexion ; rien n'a dit que la fin était refusée.
    await vi.waitUntil(() => present('#connexion'))

    expect(visites).toEqual(['/workouts/5'])
    expect(usePage().url).toBe('/login')
    expect(refusees[0]).toBe('patch /api/v1/workouts/finish')
    expect(reseau.serveur.fins.has(5)).toBe(false)
    expect(document.body.textContent).not.toContain(FIN_NON_ENREGISTREE)
    expect(fileDurable()).toEqual([
        expect.objectContaining({
            method: 'patch',
            url: '/api/v1/workouts/finish',
            data: { workout_id: 5, ended_at: HEURE_DU_GESTE.toISOString() },
            compte: '1',
        }),
    ])

    // Reconnectée, la personne revient à la séance : la fin repart, à l'heure du geste.
    sessionExpiree = false
    delete reseau.serveur.pages
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await vi.waitUntil(() => reseau.serveur.fins.get(5) === HEURE_DU_GESTE.toISOString())
    await vi.waitUntil(() => fileDurable().length === 0)
    await flushPromises()

    expect(reseau.serveur.resume().filter((requete) => requete.startsWith('patch /api/v1/workouts/finish'))).toEqual([
        `patch /api/v1/workouts/finish {"workout_id":5,"ended_at":"${HEURE_DU_GESTE.toISOString()}"}`,
    ])

    await historiqueAuRepos()
})
