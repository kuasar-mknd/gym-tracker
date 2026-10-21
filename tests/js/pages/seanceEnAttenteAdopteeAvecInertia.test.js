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
    ouvrirLaListeDesSeances,
    present,
    rangees,
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
// Le repos se réduit à ce qui le ferme.
vi.mock('@/Components/Workout/RestTimer.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'RestTimer',
            emits: ['close'],
            setup:
                (_props, { emit }) =>
                () =>
                    rendre('button', { id: 'fermer-le-repos', onClick: () => emit('close') }),
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

/*
 * Une séance démarrée sans réseau, adoptée par le serveur (#2049), avec le vrai
 * client d'Inertia, la vraie page de séance et la vraie file hors ligne.
 *
 * La séance s'ouvre depuis sa création en file, sous l'identifiant provisoire
 * `temp-q<queueId>`. L'exercice et la série ajoutés ensuite la nomment par
 * cette entrée (`{ enAttenteDe }`) et se rangent derrière elle : aucune
 * requête n'emporte un identifiant provisoire. Au retour du réseau, la file
 * crée la séance, puis l'exercice sous son identifiant réel, puis la série ;
 * la page passe alors à la séance réelle, par une seule visite qui remplace
 * l'entrée d'historique, et pas pendant un repos.
 *
 * Le décor (les pages que sert le faux serveur, l'historique chiffré sous
 * jsdom) vient de `seanceDansLHistorique.js`.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance-demarree'

let sync
let visites
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
    ;({ sync, visites } = await ouvrirLaListeDesSeances({
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

it('ajoute exercice et série à la séance en attente sans identifiant provisoire, puis passe à la séance réelle après le repos', async () => {
    reseau.serveur.enLigne = false

    // « Démarrer » sans réseau : la création en file, puis la séance construite depuis son entrée.
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

    expect(window.location.pathname).toBe(`/workouts/en-attente/${CLE}`)
    expect(document.querySelector('[dusk="etat-de-la-seance-en-attente"]').textContent).toContain(
        'Séance démarrée sans réseau : elle sera enregistrée au retour du réseau.',
    )
    // Ce que le serveur ne connaît pas encore ne part pas : ni réglages, ni modèle.
    expect(document.querySelector('[dusk="workout-settings-button"]').disabled).toBe(true)

    // Un exercice, puis une série : elles attendent derrière la séance, qu'elles nomment par son entrée.
    await cliquer('#ajouter-le-developpe-couche')
    await vi.waitUntil(() => fileDurable().length === 2)
    await cliquer('[dusk="add-set-0"]')
    await vi.waitUntil(() => fileDurable().length === 3)

    expect(document.querySelector('[dusk="enregistrer-comme-modele"]').disabled).toBe(true)
    // « Terminer », lui, reste ouvert : la fin passe par la file, derrière la séance (#2049).
    expect(document.querySelector('[dusk="finish-workout-mobile"]').disabled).toBe(false)

    const [seance, ligne, serie] = fileDurable()

    expect(seance.id).toBe(queueId)
    expect(ligne).toMatchObject({
        url: '/api/v1/workout-lines',
        data: { workout_id: { enAttenteDe: queueId }, exercise_id: 8 },
    })
    expect(serie).toMatchObject({
        url: '/api/v1/sets',
        data: { workout_line_id: { enAttenteDe: ligne.id } },
        contexte: { workout_id: { enAttenteDe: queueId }, exercise_id: 8 },
    })
    expect(JSON.stringify(fileDurable())).not.toContain('temp-')
    expect(reseau.serveur.requetes).toEqual([])

    // Un repos commence ; le réseau revient, et la file se vide pendant qu'il tourne.
    await cliquer('[dusk="open-rest-timer"]')
    expect(present('#fermer-le-repos')).toBe(true)

    visites.length = 0
    const longueurDeLHistorique = window.history.length

    reseau.serveur.enLigne = true
    await sync.processQueue()
    await historiqueAuRepos()

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/workouts {"started_at":"2026-10-19T08:15:00.000Z"}',
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":8}',
        expect.stringMatching(/^post \/api\/v1\/sets \{"workout_line_id":70,/),
    ])
    expect(fileDurable()).toEqual([])
    // Le repos tient la page : pas de visite tant qu'il tourne.
    expect(visites).toEqual([])
    expect(window.location.pathname).toBe(`/workouts/en-attente/${CLE}`)

    // Fin du repos : une seule visite, celle de la séance réelle, qui remplace l'entrée d'historique.
    await cliquer('#fermer-le-repos')
    await vi.waitUntil(() => window.location.pathname === '/workouts/42')
    await vi.waitUntil(() => !present('[dusk="etat-de-la-seance-en-attente"]'))
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect(window.history.length).toBe(longueurDeLHistorique)
    expect(usePage().props.workout.id).toBe(42)
    // Rien ne se montre deux fois : un exercice, une série, ceux du serveur.
    expect(present('[dusk="exercise-card-0"]')).toBe(true)
    expect(present('[dusk="exercise-card-1"]')).toBe(false)
    expect(rangees()).toBe(1)
    expect(present('[dusk="set-unsynced-0-0"]')).toBe(false)
    expect(document.querySelector('[dusk="workout-settings-button"]').disabled).toBe(false)
    expect(JSON.stringify([reseau.serveur.requetes, visites])).not.toContain('temp-')

    await historiqueAuRepos()
})
