import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
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
 * La copie de la séance et une écriture que le serveur refuse au vidage
 * (#2048, partie D) : la file est vide, plus rien ne vole, mais l'écran garde
 * la valeur refusée sur la série marquée « non enregistrée », pour que la
 * personne la corrige. Elle n'est pas enregistrée : elle n'entre pas dans la
 * copie. Vrai client d'Inertia, vraie page de séance, vrai service de
 * synchronisation, magasin chiffré sur une base en mémoire ; un fichier, un
 * test.
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

/** La première série de la séance 5 telle que la copie la garde. */
const premiereSerieGardee = async () =>
    (await magasin.lireUneCopie('/workouts/5'))?.page.props.workout.workout_lines[0].sets[0]

/** Une condition, le temps que la saisie parte après son propre délai. */
const attendre = (condition) => vi.waitUntil(condition, { timeout: 4000 })

/** Plus que l'anti-rebond de l'instantané, en temps réel, comme la page. */
const auDelaDeLAntiRebond = () => new Promise((resoudre) => setTimeout(resoudre, delai + 300))

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

it('ne verse pas dans la copie la valeur que le serveur a refusée au vidage, restée à l’écran', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await ecriture.ecrituresFinies()

    expect(await premiereSerieGardee()).toMatchObject({ id: 3, weight: 80 })

    // Sans réseau, le poids part en file.
    reseau.serveur.enLigne = false
    await saisir('[dusk="weight-input-0-0"]', '999')
    await attendre(() => sync.enAttente() === 1)

    // Le réseau revient, et le serveur refuse le poids : la file se vide, la série reste marquée.
    reseau.serveur.enLigne = true
    reseau.serveur.imposer.push(() => {
        throw { response: { status: 422, data: { errors: { weight: ['Le poids est trop grand.'] } } } }
    })
    window.dispatchEvent(new Event('online'))
    await attendre(() => sync.enAttente() === 0 && sync.ecrituresEnCours() === 0)
    await attendre(() => present('[dusk="set-unsynced-0-0"]'))

    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('999')

    await auDelaDeLAntiRebond()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect((await premiereSerieGardee()).weight).toBe(80)

    await historiqueAuRepos()
}, 30_000)
