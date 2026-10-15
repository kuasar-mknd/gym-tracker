import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
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
vi.mock('@/Components/Workout/AjoutDExerciceModal.vue', () => ({
    default: { name: 'AjoutDExerciceModal', render: () => null },
}))
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/Workout/WorkoutFinishModal.vue', () => ({
    default: { name: 'WorkoutFinishModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))
vi.mock('@/Components/Workout/RestTimer.vue', () => ({ default: { name: 'RestTimer', render: () => null } }))

/*
 * Les boutons Retour et Avance du navigateur, sans réseau, avec le vrai client
 * d'Inertia et la vraie page de séance (#2048).
 *
 * Inertia relit la séance dans son historique chiffré, sans rien demander au
 * serveur : les props sont celles de la visite, d'avant la série ajoutée hors
 * ligne. La page reconstruisait sa copie depuis ces seules props, et la série
 * revenait absente, sans `cloud_off`, alors que la file la gardait. Elle ne
 * réapparaissait qu'au retour du réseau, après un rechargement.
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

it('montre, au retour par Avance, la série ajoutée et cochée sans réseau, et la saisie suivante rejoint la même entrée', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    expect(rangees()).toBe(1)

    // Plus de réseau : la série ajoutée part en file, puis elle est cochée.
    reseau.serveur.enLigne = false
    await cliquer('[dusk="add-set-0"]')
    await cliquer('[dusk="complete-set-0-1"]')

    const [entree] = fileDurable()
    expect(fileDurable()).toHaveLength(1)
    expect(entree.data).toMatchObject({ workout_line_id: 1, is_completed: true })

    visites.length = 0

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')

    // Relue dans l'historique, sans le serveur : la série est là, cochée, marquée.
    expect(visites).toEqual([])
    expect(rangees()).toBe(2)
    expect(present('[dusk="set-unsynced-0-1"]')).toBe(true)
    expect(document.querySelector('[dusk="set-unsynced-0-1"]').getAttribute('aria-label')).toBe(
        'Série 2 non enregistrée',
    )
    expect(document.querySelector('[dusk="complete-set-0-1"]').getAttribute('aria-label')).toBe('Annuler la série')

    // Saisie après le retour : elle se fond dans la même entrée, qui reste seule.
    await saisir('[dusk="weight-input-0-1"]', '82.5')

    expect(fileDurable()).toHaveLength(1)
    expect(fileDurable()[0]).toMatchObject({
        id: entree.id,
        data: { workout_line_id: 1, is_completed: true, weight: 82.5 },
    })

    // Le réseau revient : une seule création, une seule série en base, et la séance rechargée ne la double pas.
    reseau.serveur.enLigne = true
    await sync.processQueue()
    await vi.waitUntil(() => visites.length > 0)
    await vi.waitUntil(() => !present('[dusk="set-unsynced-0-1"]'))
    await flushPromises()

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/sets {"workout_line_id":1,"is_completed":true,"weight":82.5,"reps":5}',
    ])
    expect([...reseau.serveur.series.values()].filter((serie) => serie.workout_line_id === 1)).toHaveLength(2)
    expect(visites).toEqual(['/workouts/5'])
    expect(rangees()).toBe(2)
    expect(fileDurable()).toEqual([])

    // Ce qu'Inertia écrit encore (la réponse d'un rechargement, une séance gardée) part avant la fin du test.
    await historiqueAuRepos()
})
