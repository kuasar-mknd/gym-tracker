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
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))

/*
 * « Terminer » une séance démarrée sans réseau (#2049), avec le vrai client
 * d'Inertia, la vraie page de séance, sa vraie question de fin et la vraie
 * file hors ligne.
 *
 * La séance n'a pas encore d'identifiant : la fin la nomme par l'entrée de sa
 * création (`{ enAttenteDe }`), comme ses exercices et ses séries, et entre en
 * file à part, la dernière de ses écritures. Fondue dans la création, la
 * séance serait née close (ou, le serveur ignorant le champ, jamais close),
 * et ses séries seraient arrivées sur une séance close. Au retour du réseau,
 * la file crée la séance, ses lignes et ses séries, puis la clôt sous son
 * identifiant réel ; la page passe à la séance réelle, close.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance-terminee'
const HEURE_DU_GESTE = new Date('2026-10-19T17:05:00.000Z')

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
    vi.useRealTimers()
    await fermerLaListeDesSeances()
})

it('met la fin d’une séance démarrée sans réseau en file à part, la dernière, puis la clôt sous son identifiant réel', async () => {
    reseau.serveur.enLigne = false

    // « Démarrer » sans réseau : la création en file, puis la séance construite depuis son entrée.
    const queueId = sync.mettreEnFile({
        method: 'post',
        url: '/api/v1/workouts',
        data: { started_at: '2026-10-19T16:15:00.000Z' },
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

    // Un exercice, une série : derrière la séance, qu'ils nomment par son entrée.
    await cliquer('#ajouter-le-developpe-couche')
    await vi.waitUntil(() => fileDurable().length === 2)
    await cliquer('[dusk="add-set-0"]')
    await vi.waitUntil(() => fileDurable().length === 3)

    // « Terminer » reste ouvert sur la séance en attente ; « Modèle », lui, attend.
    expect(document.querySelector('[dusk="finish-workout-mobile"]').disabled).toBe(false)
    expect(document.querySelector('[dusk="enregistrer-comme-modele"]').disabled).toBe(true)

    await cliquer('[dusk="finish-workout-mobile"]')
    await vi.waitUntil(() => present('dialog[open] [dusk="confirm-finish-button"]'))
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(HEURE_DU_GESTE)
    await cliquer('[dusk="confirm-finish-button"]')
    await vi.waitUntil(() => fileDurable().length === 4)
    vi.useRealTimers()
    await vi.waitUntil(() => !present('dialog[open]'))

    const [creation, ligne, serie, fin] = fileDurable()

    // La fin, à part et la dernière : la création ne porte aucune fin.
    expect(creation.id).toBe(queueId)
    expect(creation.data).toEqual({ started_at: '2026-10-19T16:15:00.000Z' })
    expect([ligne.url, serie.url]).toEqual(['/api/v1/workout-lines', '/api/v1/sets'])
    expect(fin).toMatchObject({
        method: 'patch',
        url: '/api/v1/workouts/finish',
        data: { workout_id: { enAttenteDe: queueId }, ended_at: HEURE_DU_GESTE.toISOString() },
    })
    expect(JSON.stringify(fileDurable())).not.toContain('temp-')

    // La séance se montre close, à l'heure du geste ; plus rien ne s'y ajoute.
    await vi.waitUntil(() =>
        document
            .querySelector('[dusk="etat-de-la-seance-en-attente"] p')
            ?.textContent.includes(messageDeLaFinEnAttente(HEURE_DU_GESTE.toISOString())),
    )
    expect(present('[dusk="finish-workout-mobile"]')).toBe(false)
    expect(present('[dusk="add-set-0"]')).toBe(false)
    expect(reseau.serveur.requetes).toEqual([])

    // Le réseau revient : la séance, la ligne, la série, puis la fin, sous l'identifiant réel.
    visites.length = 0
    reseau.serveur.enLigne = true
    await sync.processQueue()

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/workouts {"started_at":"2026-10-19T16:15:00.000Z"}',
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":8}',
        expect.stringMatching(/^post \/api\/v1\/sets \{"workout_line_id":70,/),
        `patch /api/v1/workouts/finish {"workout_id":42,"ended_at":"${HEURE_DU_GESTE.toISOString()}"}`,
    ])
    // Rien de refusé : la série est arrivée avant la fin, sur une séance ouverte.
    expect([...reseau.serveur.series.values()]).toHaveLength(1)
    expect(sync.failedRequests()).toEqual([])
    expect(fileDurable()).toEqual([])

    // La page passe à la séance réelle, close.
    await vi.waitUntil(() => window.location.pathname === '/workouts/42')
    await vi.waitUntil(() => usePage().props.workout.ended_at === HEURE_DU_GESTE.toISOString())
    await vi.waitUntil(() => !present('[dusk="etat-de-la-seance-en-attente"]'))
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect(rangees()).toBe(1)
    expect(present('[dusk="finish-workout-mobile"]')).toBe(false)

    await historiqueAuRepos()
})
