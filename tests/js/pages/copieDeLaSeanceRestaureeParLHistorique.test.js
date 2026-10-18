import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    naviguerDansLHistorique,
    ouvrirLaListeDesSeances,
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
 * La copie de la séance et l'historique de l'onglet (#2048, partie D) : les
 * boutons Retour et Avance rendent la séance d'une visite, sans le serveur.
 * Une visite antérieure de la même séance n'a pas ce qu'une visite plus
 * récente a fait enregistrer : son instantané effacerait de la copie une
 * série enregistrée. Il n'en est pris que si la séance rendue est exactement
 * celle de la copie. Vrai client d'Inertia, vraie page de séance, magasin
 * chiffré sur une base en mémoire ; un fichier, un test.
 */

const pages = suivreLesPagesMontees()

/** La clé du témoin, posée comme le serveur la pose : 32 octets en base64url. */
const CLE = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA'

let visiter
let sync
let magasin
let ecriture
let delai
let fabriqueDeLaBase

/** La séance 5 telle que la copie la garde. */
const seanceGardee = async () => (await magasin.lireUneCopie('/workouts/5'))?.page.props.workout

/** Les séries de la séance gardée, en une ligne chacune. */
const seriesGardees = async () =>
    (await seanceGardee()).workout_lines[0].sets.map(
        (serie) => `${serie.id} ${serie.weight} ${serie.is_completed ? 'faite' : 'à faire'}`,
    )

/** Une condition, le temps que la saisie parte après son propre délai. */
const attendre = (condition) => vi.waitUntil(condition, { timeout: 4000 })

/** Plus que l'anti-rebond de l'instantané, en temps réel, comme la page. */
const auDelaDeLAntiRebond = () => new Promise((resoudre) => setTimeout(resoudre, delai + 300))

/**
 * Les écritures attendues ont atteint le serveur et sont revenues, et ce que
 * l'instantané devait verser est écrit.
 *
 * @param {number} requetes le nombre de requêtes que le serveur doit avoir reçues
 */
const toutEstPasse = async (requetes) => {
    await attendre(() => reseau.serveur.requetes.length === requetes && sync.ecrituresEnCours() === 0)
    await auDelaDeLAntiRebond()
    await ecriture.ecrituresFinies()
}

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    document.cookie = `gt_copies=${CLE}; path=/`
    fabriqueDeLaBase = creerUneFabriqueEnMemoire()

    ;({ visiter, sync } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        // Comme `main.js` : l'écriture des copies, après la garde de l'historique, avant Inertia.
        avantInertia: async ({ router }) => {
            const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
            const { installerLEcritureDesCopies } = await import('@/Utils/ecritureDesCopies')
            ;({ DELAI_DE_L_INSTANTANE_MS: delai } = await import('@/composables/useCopieDeLaSeance'))

            magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabriqueDeLaBase) })
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

it('ne verse pas dans la copie la séance d’une visite plus ancienne que l’historique rend, et verse celle de la dernière', async () => {
    // Première visite : le poids passe à 85.
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await saisir('[dusk="weight-input-0-0"]', '85')
    await toutEstPasse(1)

    expect(await seriesGardees()).toEqual(['3 85 faite'])

    // La liste, puis une seconde visite : une série ajoutée, enregistrée.
    await visiter('/workouts', '#liste-des-seances')
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await cliquer('[dusk="add-set-0"]')
    await toutEstPasse(2)

    expect(await seriesGardees()).toEqual(['3 85 faite', '100 85 à faire'])

    await visiter('/workouts', '#liste-des-seances')

    // Retour : la seconde visite, telle que la copie la garde. Ce qu'on y fait entre dans la copie.
    await naviguerDansLHistorique('back', '[dusk="exercise-card-0"]')
    expect(rangees()).toBe(2)
    await cliquer('[dusk="complete-set-0-1"]')
    await toutEstPasse(3)

    expect(await seriesGardees()).toEqual(['3 85 faite', '100 85 faite'])

    // Retour, deux fois : la première visite, d'avant la série ajoutée. Sa séance n'entre pas dans la copie.
    await naviguerDansLHistorique('back', '#liste-des-seances')
    await naviguerDansLHistorique('back', '[dusk="exercise-card-0"]')
    expect(rangees()).toBe(1)
    await saisir('[dusk="weight-input-0-0"]', '90')
    await cliquer('[dusk="complete-set-0-0"]')
    await toutEstPasse(5)
    expect(reseau.serveur.resume().slice(3)).toEqual([
        'patch /api/v1/sets/3 {"weight":90}',
        'patch /api/v1/sets/3 {"is_completed":false}',
    ])

    expect(await seriesGardees()).toEqual(['3 85 faite', '100 85 faite'])

    // L'écriture acceptée, elle, est notée : la copie pourra dire qu'elle n'a pas tout.
    expect(JSON.parse(localStorage.getItem('gym-tracker:ecritures-acceptees')).seances['5']).toBeGreaterThan(
        (await magasin.lireUneCopie('/workouts/5')).datesDesProps.workout,
    )

    await historiqueAuRepos()
}, 40_000)
