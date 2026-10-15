import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    historiqueEcrit,
    naviguerDansLHistorique,
    ouvrirLaListeDesSeances,
    present,
    rangees,
    saisir,
    uneReponseRetenue,
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
 * Une série ajoutée et cochée en ligne, puis les boutons Retour et Avance, avec
 * le vrai client d'Inertia et la vraie page de séance (#2048).
 *
 * Inertia rend au retour la page de la visite : la séance s'écrit par l'API,
 * sans visite, et la série enregistrée en manquait. Deux séries devenaient une,
 * en ligne comme hors ligne, et rien ne la rendait avant le prochain rendu du
 * serveur ; un poids enregistré revenait à celui de la visite, et un refus
 * ramenait ce dernier. L'entrée d'historique de la page garde désormais la
 * séance à jour, et la page en relève les valeurs du serveur.
 *
 * Elle ne doit pas, en retour, écrire dans l'entrée d'une autre page. Inertia
 * range l'état mémorisé dans l'entrée COURANTE, et le bouton Retour change
 * d'entrée avant que la séance parte : une écriture qui aboutit à ce moment
 * rangeait la séance, adresse comprise, dans l'entrée de la liste.
 *
 * Le décor (les pages que sert le faux serveur, l'historique chiffré sous
 * jsdom) vient de `seanceDansLHistorique.js`.
 */

const pages = suivreLesPagesMontees()

let visites
let visiter

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })

    ;({ visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    await fermerLaListeDesSeances()
})

it('montre, au retour par Avance, la série enregistrée en ligne, sans écrire dans l’entrée de la liste', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    expect(rangees()).toBe(1)

    // En ligne : la série est créée, puis cochée, et le poids de la première passe à 85 ; le serveur accepte tout.
    await cliquer('[dusk="add-set-0"]')
    await cliquer('[dusk="complete-set-0-1"]')
    await saisir('[dusk="weight-input-0-0"]', '85')
    await cliquer('[dusk="complete-set-0-0"]')

    expect(reseau.serveur.resume()).toEqual([
        'post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":80,"reps":5}',
        'patch /api/v1/sets/100 {"is_completed":true}',
        'patch /api/v1/sets/3 {"weight":85}',
        'patch /api/v1/sets/3 {"is_completed":false}',
    ])

    visites.length = 0

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')

    // Relue dans l'historique, sans le serveur : les deux séries, telles qu'enregistrées.
    expect(visites).toEqual([])
    expect(rangees()).toBe(2)
    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('85')
    expect(document.querySelector('[dusk="complete-set-0-0"]').getAttribute('aria-label')).toBe('Valider la série')
    expect(document.querySelector('[dusk="complete-set-0-1"]').getAttribute('aria-label')).toBe('Annuler la série')
    expect(present('[dusk="set-unsynced-0-1"]')).toBe(false)

    // Elle a gardé son identifiant : la décocher vise la série en base.
    await cliquer('[dusk="complete-set-0-1"]')
    expect(reseau.serveur.resume().at(-1)).toBe('patch /api/v1/sets/100 {"is_completed":false}')

    // Un poids refusé revient au dernier que le serveur a accepté, 85, pas à celui de la visite.
    reseau.serveur.imposer.push(() => {
        throw { response: { status: 422, data: { errors: { weight: ['Le poids est trop grand.'] } } } }
    })
    await saisir('[dusk="weight-input-0-0"]', '99999')
    await cliquer('[dusk="complete-set-0-0"]')

    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('85')

    // Une série en route pendant le retour arrière : sa réponse arrive une fois l'entrée de la liste rendue courante.
    const liberer = uneReponseRetenue(reseau.serveur)
    await cliquer('[dusk="add-set-0"]')
    window.addEventListener('popstate', liberer, { once: true })

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await vi.waitUntil(() => reseau.serveur.series.size === 3)
    await historiqueEcrit()

    // L'entrée de la liste reste la sienne : son adresse, et sa page au retour suivant.
    expect(window.location.pathname).toBe('/workouts')
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')
    expect(window.location.pathname).toBe('/workouts/5')
    await naviguerDansLHistorique('back', '#liste-des-seances')
    expect(window.location.pathname).toBe('/workouts')
    expect(present('[dusk="exercise-card-0"]')).toBe(false)
    expect(visites).toEqual([])

    // Ce qu'Inertia écrit encore (la réponse d'un rechargement, une séance gardée) part avant la fin du test.
    await historiqueAuRepos()
})
