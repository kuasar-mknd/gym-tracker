import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
    rangees,
} from './seanceDansLHistorique'
import { messageDeLaFinEnAttente } from '@/Utils/seanceEnAttente'

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
 * « Terminer » sans réseau (#2049), avec le vrai client d'Inertia, la vraie
 * page de séance, sa vraie question de fin et la vraie file hors ligne.
 *
 * La séance restait ouverte, et la question disait que la fin n'était pas
 * confirmée (#2048) : la personne repartait de la salle sans pouvoir terminer
 * sa séance. La fin entre désormais en file, à l'heure du geste, derrière les
 * séries qui attendent ; la séance se montre close ; au retour du réseau, la
 * file envoie les séries puis la fin, et la séance rechargée est close.
 */

const pages = suivreLesPagesMontees()

/**
 * L'heure du geste : une heure avant le chargement du fichier, à la minute.
 * La fin mise en file la garde après le retour de l'horloge réelle, et la file
 * abandonne au vidage ce qui attend depuis plus de trente jours : écrite en
 * dur, cette heure faisait tomber le test trente jours après (#2123).
 */
const HEURE_DU_GESTE = new Date(Math.floor((Date.now() - 3_600_000) / 60_000) * 60_000)

let sync
let visites
let visiter
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })

    ;({ sync, visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: (inertia) => {
            usePage = inertia.usePage
        },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    vi.useRealTimers()
    await fermerLaListeDesSeances()
})

it('met la fin en file derrière les séries, à l’heure du geste, montre la séance close, et l’envoie après elles au retour du réseau', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')

    // Plus de réseau : deux séries ajoutées partent en file.
    reseau.serveur.enLigne = false
    await cliquer('[dusk="add-set-0"]')
    await cliquer('[dusk="add-set-0"]')
    await vi.waitUntil(() => fileDurable().length === 2)

    // « Terminer », puis « Confirmer » à l'heure du geste.
    await cliquer('[dusk="finish-workout-mobile"]')
    await vi.waitUntil(() => present('dialog[open] [dusk="confirm-finish-button"]'))
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(HEURE_DU_GESTE)
    await cliquer('[dusk="confirm-finish-button"]')
    await vi.waitUntil(() => fileDurable().length === 3)
    vi.useRealTimers()
    await vi.waitUntil(() => !present('dialog[open]'))

    const [premiere, seconde, fin] = fileDurable()

    expect([premiere.url, seconde.url]).toEqual(['/api/v1/sets', '/api/v1/sets'])
    expect(fin).toMatchObject({
        method: 'patch',
        url: '/api/v1/workouts/finish',
        data: { workout_id: 5, ended_at: HEURE_DU_GESTE.toISOString() },
    })
    expect(reseau.serveur.requetes).toEqual([])

    // La séance se montre close, à l'heure du geste, et rien ne dit que la fin n'est pas confirmée.
    await vi.waitUntil(() => present('[dusk="etat-de-la-seance-en-attente"]'))

    expect(document.querySelector('[dusk="etat-de-la-seance-en-attente"] p').textContent).toBe(
        messageDeLaFinEnAttente(HEURE_DU_GESTE.toISOString()),
    )
    expect(present('[dusk="seance-en-attente-accueil"]')).toBe(true)
    expect(present('[dusk="finish-workout-mobile"]')).toBe(false)
    expect(present('[dusk="finish-workout-offline"]')).toBe(false)
    expect(document.body.textContent).not.toContain('pas confirmée')
    expect(rangees()).toBe(3)
    expect(window.location.pathname).toBe('/workouts/5')

    // Le réseau revient : les séries partent, puis la fin, dans cet ordre.
    visites.length = 0
    reseau.serveur.enLigne = true
    await sync.processQueue()

    expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
        'post /api/v1/sets',
        'post /api/v1/sets',
        'patch /api/v1/workouts/finish',
    ])
    expect(reseau.serveur.fins.get(5)).toBe(HEURE_DU_GESTE.toISOString())
    expect(fileDurable()).toEqual([])

    // La fin rejouée, l'avis s'en va : la séance est close pour le serveur, à l'heure qu'il a gardée.
    await vi.waitUntil(() => !present('[dusk="etat-de-la-seance-en-attente"]'))

    expect(present('[dusk="finish-workout-mobile"]')).toBe(false)

    // Rechargée, la séance est close : c'est le serveur qui le dit.
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await vi.waitUntil(() => usePage().props.workout.ended_at === HEURE_DU_GESTE.toISOString())
    await flushPromises()

    // La visite, et au pire le rechargement de trop d'une page montée avant la réponse (`useRechargementApresLeVidage`).
    expect(new Set(visites)).toEqual(new Set(['/workouts/5']))
    expect(present('[dusk="etat-de-la-seance-en-attente"]')).toBe(false)
    expect(present('[dusk="finish-workout-mobile"]')).toBe(false)
    expect(rangees()).toBe(3)

    await historiqueAuRepos()
})
