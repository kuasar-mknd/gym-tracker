import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    pagesDuServeur,
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
 * La copie de la séance et un document rouvert par le bouton Retour (#2048,
 * partie D, relecture du 2026-10-17), avec le vrai client d'Inertia et la
 * vraie page de séance. Le document vient du serveur, frais, et Inertia monte
 * sa page ; puis il la remplace par l'entrée d'historique, sans la remonter
 * (`InitialVisit.handleBackForward`). L'entrée est celle d'une visite plus
 * ancienne que la copie : la page la montre, et la copie, relue pour être
 * comparée, est celle du document. Comparée à la séance du document, la copie
 * laissait verser l'instantané de l'entrée, et une série enregistrée en
 * sortait. Magasin chiffré sur une base en mémoire ; un fichier, un test.
 */

const pages = suivreLesPagesMontees()

/** La clé du témoin, posée comme le serveur la pose : 32 octets en base64url. */
const CLE = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA'

let sync
let magasin
let ecriture
let delai
let fabriqueDeLaBase

/** Les séries de la séance 5 gardée, en une ligne chacune. */
const seriesGardees = async () =>
    (await magasin.lireUneCopie('/workouts/5'))?.page.props.workout.workout_lines[0].sets.map(
        (serie) => `${serie.id} ${serie.weight} ${serie.is_completed ? 'faite' : 'à faire'}`,
    )

/** Plus que l'anti-rebond de l'instantané, en temps réel, comme la page. */
const auDelaDeLAntiRebond = () => new Promise((resoudre) => setTimeout(resoudre, delai + 300))

beforeAll(async () => {
    // Avant le chargement d'Inertia, qui lit le type de la navigation à son import.
    vi.spyOn(performance, 'getEntriesByType').mockImplementation((type) =>
        type === 'navigation' ? [{ type: 'back_forward' }] : [],
    )

    // L'entrée d'historique : une visite d'avant la seconde série et la coche.
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: false }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    const entreeDeLHistorique = pagesDuServeur(reseau.serveur)['/workouts/5']()

    // Le document, frais : ce que le serveur a en base maintenant, et que la copie garde déjà.
    reseau.serveur.series.set(3, { id: 3, workout_line_id: 1, weight: 85, reps: 5, is_completed: true })
    reseau.serveur.series.set(4, { id: 4, workout_line_id: 1, weight: 90, reps: 5, is_completed: true })
    document.cookie = `gt_copies=${CLE}; path=/`
    fabriqueDeLaBase = creerUneFabriqueEnMemoire()

    ;({ sync } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        ouverture: {
            adresse: '/workouts/5',
            selecteur: '[dusk="exercise-card-0"]',
            etatDeLHistorique: { page: entreeDeLHistorique },
        },
        // Comme `main.js` : l'écriture des copies, après la garde de l'historique, avant Inertia.
        avantInertia: async ({ router }) => {
            const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
            const { installerLEcritureDesCopies } = await import('@/Utils/ecritureDesCopies')
            ;({ DELAI_DE_L_INSTANTANE_MS: delai } = await import('@/composables/useCopieDeLaSeance'))

            magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabriqueDeLaBase) })
            expect(await magasin.ecrireUneCopie(pagesDuServeur(reseau.serveur)['/workouts/5']())).toBe('ecrite')
            ecriture = installerLEcritureDesCopies({ routeur: router, magasin })
        },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    ecriture.desinstaller()
    await magasin.fermer()
    document.cookie = 'gt_copies=; path=/; max-age=0'
    await fermerLaListeDesSeances()
})

it('ne verse pas dans la copie la séance d’une entrée d’historique plus ancienne, posée sur un document rouvert', async () => {
    expect(await seriesGardees()).toEqual(['3 85 faite', '4 90 faite'])

    // Inertia a remplacé la page du document par l'entrée d'historique, sans la remonter.
    await vi.waitUntil(() => rangees() === 1, { timeout: 3000 })
    await auDelaDeLAntiRebond()
    await ecriture.ecrituresFinies()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect(reseau.serveur.requetes).toEqual([])
    expect(await seriesGardees()).toEqual(['3 85 faite', '4 90 faite'])

    // Une écriture acceptée depuis l'entrée ne fait pas non plus verser la séance qu'elle montre.
    await saisir('[dusk="weight-input-0-0"]', '95')
    await vi.waitUntil(() => reseau.serveur.requetes.length === 1 && sync.ecrituresEnCours() === 0, {
        timeout: 4000,
    })
    await auDelaDeLAntiRebond()
    await ecriture.ecrituresFinies()

    expect(reseau.serveur.resume()).toEqual(['patch /api/v1/sets/3 {"weight":95}'])
    expect(await seriesGardees()).toEqual(['3 85 faite', '4 90 faite'])

    await historiqueAuRepos()
}, 30_000)
