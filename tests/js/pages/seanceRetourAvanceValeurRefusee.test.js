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
 * Une valeur saisie, et une coche, que la personne quitte par Retour avant la
 * réponse du serveur, puis que le serveur refuse : le bouton Avance, avec le
 * vrai client d'Inertia et la vraie page de séance (#2048).
 *
 * L'entrée d'historique gardait la séance telle qu'à l'écran. Le refus,
 * arrivé après le départ, ne rétablissait que la page quittée : Avance
 * rendait la valeur refusée comme enregistrée, sans marque, la page la
 * relevait comme confirmée, et la base gardait l'ancienne. Toute frappe faite
 * moins d'une seconde avant Retour part au démontage, donc après le départ.
 * L'entrée garde désormais, pour une écriture sans réponse, la valeur que la
 * page rétablit si le serveur refuse.
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

it('rend au retour par Avance la valeur et la coche que le serveur garde, quand il refuse après le départ', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    const requetes = () => reseau.serveur.resume().length

    // Un poids tapé juste avant Retour : il part au départ de la page, et le serveur le refuse.
    reseau.serveur.imposer.push(() => {
        throw { response: { status: 422, data: { errors: { weight: ['Le poids est trop grand.'] } } } }
    })
    await saisir('[dusk="weight-input-0-0"]', '99999')

    visites.length = 0
    await naviguerDansLHistorique('back', '#liste-des-seances')
    await vi.waitUntil(() => requetes() === 1)
    await historiqueEcrit()
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')

    expect(reseau.serveur.resume()).toEqual(['patch /api/v1/sets/3 {"weight":99999}'])
    expect(reseau.serveur.series.get(3).weight).toBe(80)
    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('80')
    expect(present('[dusk="set-unsynced-0-0"]')).toBe(false)
    expect(localStorage.getItem('draft_set_3')).toBeNull()

    // Une coche partie juste avant Retour, refusée une fois l'entrée de la liste rendue courante.
    reseau.serveur.imposer.push(() => {
        throw { response: { status: 403, data: { message: 'Cette séance est terminée.' } } }
    })
    const liberer = uneReponseRetenue(reseau.serveur)
    await cliquer('[dusk="complete-set-0-0"]')
    expect(document.querySelector('[dusk="complete-set-0-0"]').getAttribute('aria-label')).toBe('Valider la série')
    window.addEventListener('popstate', liberer, { once: true })

    await naviguerDansLHistorique('back', '#liste-des-seances')
    await vi.waitUntil(() => requetes() === 2)
    await historiqueEcrit()
    await naviguerDansLHistorique('forward', '[dusk="exercise-card-0"]')

    expect(reseau.serveur.resume().at(-1)).toBe('patch /api/v1/sets/3 {"is_completed":false}')
    expect(reseau.serveur.series.get(3).is_completed).toBe(true)
    expect(document.querySelector('[dusk="complete-set-0-0"]').getAttribute('aria-label')).toBe('Annuler la série')
    expect(visites).toEqual([])

    // Ce que la page relève comme confirmé est bien ce que le serveur garde : un nouveau refus y revient.
    reseau.serveur.imposer.push(() => {
        throw { response: { status: 422, data: { errors: { weight: ['Le poids est trop grand.'] } } } }
    })
    await saisir('[dusk="weight-input-0-0"]', '88888')
    await cliquer('[dusk="complete-set-0-0"]')
    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('80')

    // Ce qu'Inertia écrit encore (la réponse d'un rechargement, une séance gardée) part avant la fin du test.
    await historiqueAuRepos()
})
