import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    DEVELOPPE_COUCHE,
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    naviguerDansLHistorique,
    ouvrirLaListeDesSeances,
    present,
    rangees,
    saisir,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page et les modales, fermées ici, n'ont rien à montrer : la séance seule compte.
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
 * Deux entrées d'historique de la même séance : la séance, la liste par la
 * flèche de l'en-tête (un lien d'Inertia, qui pousse une entrée), puis la même
 * séance rouverte depuis la liste. Dans la plus récente, un exercice est
 * ajouté en ligne, puis le réseau tombe et la série ajoutée sous lui part en
 * file. Retour, puis Retour : la plus ancienne revient, avec le vrai client
 * d'Inertia et la vraie page de séance (#2048).
 *
 * Cette entrée-là a été figée avant l'exercice. La superposition de la file
 * écartait sans bruit la série, dont la ligne n'existait pas dans cette
 * séance : l'exercice et la série disparaissaient, sans `cloud_off`, alors que
 * la file gardait la série. La personne la refaisait, et le vidage en créait
 * une seconde. L'entrée de file porte désormais la séance et l'exercice de sa
 * ligne (`contexte`), d'où la page reconstruit la ligne.
 *
 * Le décor (les pages que sert le faux serveur, l'historique chiffré sous
 * jsdom) vient de `seanceDansLHistorique.js`.
 */

const pages = suivreLesPagesMontees()

let sync
let visites
let visiter

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })

    ;({ sync, visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    await fermerLaListeDesSeances()
})

it('montre, dans une visite plus ancienne de la même séance, l’exercice ajouté depuis et la série en file sous lui', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await visiter('/workouts', '#liste-des-seances')
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')

    // En ligne, dans la visite la plus récente : l'exercice est créé sur le serveur.
    await cliquer('#ajouter-le-developpe-couche')
    expect([...reseau.serveur.lignes.keys()]).toEqual([1, 70])

    // Plus de réseau : la série ajoutée à cet exercice part en file, puis elle est cochée.
    reseau.serveur.enLigne = false
    await cliquer('[dusk="add-set-1"]')
    await cliquer('[dusk="complete-set-1-0"]')

    const [entree] = fileDurable()
    expect(fileDurable()).toHaveLength(1)
    expect(entree).toMatchObject({
        data: { workout_line_id: 70, is_completed: true },
        contexte: { workout_id: 5, exercise_id: 8 },
    })

    visites.length = 0

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('back', '[dusk="exercise-card-0"]')

    // La visite d'avant l'exercice, relue sans le serveur : l'exercice est là, et sa série, cochée et marquée.
    expect(visites).toEqual([])
    expect(window.location.pathname).toBe('/workouts/5')
    expect(document.querySelector('[dusk="exercise-card-1"]').textContent).toContain(DEVELOPPE_COUCHE.name)
    expect(rangees(1)).toBe(1)
    expect(document.querySelector('[dusk="set-unsynced-1-0"]').getAttribute('aria-label')).toBe(
        'Série 1 non enregistrée',
    )
    expect(document.querySelector('[dusk="complete-set-1-0"]').getAttribute('aria-label')).toBe('Annuler la série')

    // La saisie qui suit se fond dans la même entrée, qui reste seule.
    await saisir('[dusk="weight-input-1-0"]', '60')

    expect(fileDurable()).toEqual([
        expect.objectContaining({
            id: entree.id,
            data: expect.objectContaining({ workout_line_id: 70, is_completed: true, weight: 60 }),
        }),
    ])

    // Le réseau revient : une seule création, une seule série sous l'exercice, et le rechargement ne la double pas.
    reseau.serveur.enLigne = true
    await sync.processQueue()
    await vi.waitUntil(() => visites.length > 0)
    await vi.waitUntil(() => !present('[dusk="set-unsynced-1-0"]'))
    await flushPromises()

    expect(reseau.serveur.resume().filter((requete) => requete.startsWith('post /api/v1/sets'))).toEqual([
        'post /api/v1/sets {"workout_line_id":70,"is_completed":true,"weight":60,"reps":10}',
    ])
    expect([...reseau.serveur.series.values()].filter((serie) => serie.workout_line_id === 70)).toHaveLength(1)
    expect([...reseau.serveur.lignes.keys()]).toEqual([1, 70])
    expect(document.querySelectorAll('[dusk^="exercise-card-"]')).toHaveLength(2)
    expect(rangees(1)).toBe(1)
    expect(fileDurable()).toEqual([])

    // Ce qu'Inertia écrit encore (la réponse d'un rechargement, une séance gardée) part avant la fin du test.
    await historiqueAuRepos()
})
