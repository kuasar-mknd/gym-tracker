import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    DEVELOPPE_COUCHE,
    SQUAT,
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    naviguerDansLHistorique,
    ouvrirLaListeDesSeances,
    present,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page et les modales n'ont rien à montrer : la séance seule compte.
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
// La modale d'ajout se réduit au choix qu'elle émet : le développé couché.
vi.mock('@/Components/Workout/AjoutDExerciceModal.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AjoutDExerciceModal',
            emits: ['add'],
            setup:
                (_props, { emit }) =>
                () =>
                    rendre('button', { id: 'ajouter-le-developpe-couche', onClick: () => emit('add', 8) }),
        },
    }
})
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/Workout/WorkoutFinishModal.vue', () => ({
    default: { name: 'WorkoutFinishModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))
vi.mock('@/Components/Workout/RestTimer.vue', () => ({ default: { name: 'RestTimer', render: () => null } }))

/*
 * Les boutons Retour et Avance sur une séance démarrée sans réseau (#2049),
 * avec le vrai client d'Inertia, la vraie page de séance et la vraie file.
 *
 * L'entrée d'historique de la séance en attente garde la page construite depuis
 * sa création, chiffrée comme toute page du compte. Rendue par Avance, la
 * séance revient de la file : l'exercice ajouté sans réseau, qui la nomme par
 * son entrée, s'y superpose. Rendue après que le serveur l'a créée, sa
 * création n'est plus en file : la page ouvre la séance réelle, à sa place
 * dans l'historique, et non une séance provisoire qui n'attend plus rien.
 *
 * Le décor vient de `seanceDansLHistorique.js`.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance-rouverte'

let sync
let visites
let visiter
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
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
    await fermerLaListeDesSeances()
})

it('rend la séance en attente depuis la file par Avance, puis la séance réelle par Retour une fois créée', async () => {
    reseau.serveur.enLigne = false

    const queueId = sync.mettreEnFile({
        method: 'post',
        url: '/api/v1/workouts',
        data: { started_at: '2026-10-19T08:15:00.000Z' },
        headers: { 'Idempotency-Key': CLE },
    })
    const { ouvrirLaSeanceEnAttente } = await import('@/composables/useDemarrageDUneSeance')

    await ouvrirLaSeanceEnAttente({
        entree: sync.entreeStockee(queueId),
        page: usePage(),
        lireUneCopie: async (adresse) =>
            adresse === '/exercises' ? { page: { props: { exercises: [SQUAT, DEVELOPPE_COUCHE] } } } : null,
    })
    await vi.waitUntil(() => present('[dusk="etat-de-la-seance-en-attente"]'))
    await cliquer('#ajouter-le-developpe-couche')
    await vi.waitUntil(() => fileDurable().length === 2)
    await historiqueAuRepos()

    visites.length = 0

    // Retour, puis Avance : la séance revient de l'historique, et l'exercice de la file.
    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('forward', '[dusk="etat-de-la-seance-en-attente"]')

    expect(window.location.pathname).toBe(`/workouts/en-attente/${CLE}`)
    expect(usePage().props.workout.id).toBe(`temp-q${queueId}`)
    expect(present('[dusk="exercise-card-0"]')).toBe(true)
    expect(document.querySelector('[dusk="exercise-card-0"]').textContent).toContain('Développé couché')
    expect(present('[dusk="exercise-card-1"]')).toBe(false)
    expect(visites).toEqual([])
    expect(reseau.serveur.requetes).toEqual([])

    // La liste des séances, puis le réseau : la séance et son exercice sont créés pendant qu'elle est ouverte.
    reseau.serveur.enLigne = true
    await visiter('/workouts', '#liste-des-seances')
    await sync.processQueue()
    await vi.waitUntil(() => fileDurable().length === 0)
    await historiqueAuRepos()

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/workouts {"started_at":"2026-10-19T08:15:00.000Z"}',
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":8}',
    ])

    visites.length = 0

    // Retour vers l'entrée de la séance en attente : sa création n'est plus en file, la séance réelle s'ouvre.
    await naviguerDansLHistorique('back', '[dusk="exercise-card-0"]')
    await vi.waitUntil(() => window.location.pathname === '/workouts/42')
    await vi.waitUntil(() => !present('[dusk="etat-de-la-seance-en-attente"]'))
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect(usePage().props.workout.id).toBe(42)
    expect(document.querySelector('[dusk="exercise-card-0"]').textContent).toContain('Développé couché')
    expect(present('[dusk="exercise-card-1"]')).toBe(false)

    await historiqueAuRepos()
})
