import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
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
 * La copie hors ligne de la séance ouverte (#2048, partie D, section « La
 * séance » de l'issue), avec le vrai client d'Inertia, la vraie page de
 * séance, le vrai service de synchronisation et le magasin chiffré sur une
 * base en mémoire.
 *
 * La séance s'écrit par l'API, sans visite : la copie gardée de la réponse
 * n'a rien de ce que la page fait accepter ensuite. La page y verse son
 * instantané, mais seulement quand tout est enregistré, une seconde après le
 * dernier changement, et aussitôt quand elle se cache ou part. Chaque
 * écriture acceptée note sa séance et l'heure, pour dire plus tard ce qu'une
 * copie plus ancienne n'a pas.
 *
 * Le décor (les pages que sert le faux serveur, l'historique chiffré sous
 * jsdom) vient de `seanceDansLHistorique.js` : un fichier, un test.
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

/** La première série de la séance gardée. */
const premiereSerieGardee = async () => (await seanceGardee())?.workout_lines[0].sets[0]

/** Ce que la note des écritures acceptées dit de la séance 5. */
const noteDeLaSeance = () =>
    JSON.parse(localStorage.getItem('gym-tracker:ecritures-acceptees') ?? 'null')?.seances?.['5'] ?? null

/** Une condition, le temps que la saisie parte après son propre délai. */
const attendre = (condition) => vi.waitUntil(condition, { timeout: 4000 })

/**
 * Plus que l'anti-rebond de l'instantané : ce qu'il aurait pris, il l'a pris.
 * Le test attend en temps réel, comme la page : il dure quelques secondes.
 */
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

it('verse dans la copie la séance enregistrée, jamais tant qu’une écriture attend, et aussitôt quand la page se cache ou part', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await ecriture.ecrituresFinies()

    // La copie de la réponse : la série telle que le serveur l'a rendue.
    expect(await premiereSerieGardee()).toMatchObject({ id: 3, weight: 80, is_completed: true })
    expect(noteDeLaSeance()).toBeNull()

    // 1. Le poids part, et sa réponse tarde : rien n'est enregistré, rien n'est versé.
    const liberer = uneReponseRetenue(reseau.serveur)
    await saisir('[dusk="weight-input-0-0"]', '85')
    await attendre(() => sync.ecrituresEnCours() === 1)
    await auDelaDeLAntiRebond()
    await ecriture.ecrituresFinies()

    expect((await premiereSerieGardee()).weight).toBe(80)
    expect(noteDeLaSeance()).toBeNull()

    // Acceptée : la note le dit aussitôt, et l'instantané suit l'anti-rebond.
    const avantLaReponse = Date.now()
    liberer()
    await attendre(() => sync.ecrituresEnCours() === 0)

    expect(noteDeLaSeance()).toBeGreaterThanOrEqual(avantLaReponse)
    // L'anti-rebond : pas encore.
    expect((await premiereSerieGardee()).weight).toBe(80)

    await vi.waitFor(async () => expect((await premiereSerieGardee()).weight).toBe(85), { timeout: delai * 3 })

    // Une valeur tapée qui n'est pas encore partie n'entre pas, même quand la page part aussitôt.
    await saisir('[dusk="weight-input-0-0"]', '95')
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect((await premiereSerieGardee()).weight).toBe(85)

    await attendre(() => reseau.serveur.requetes.length === 2 && sync.ecrituresEnCours() === 0)
    await vi.waitFor(async () => expect((await premiereSerieGardee()).weight).toBe(95), { timeout: delai * 3 })

    // Rien d'autre que la séance : la copie garde la page de la réponse, et ce que la page ajoute à l'écran n'y entre pas.
    const copie = await magasin.lireUneCopie('/workouts/5')
    expect(copie.page.props.exercises.map((exercice) => exercice.id)).toEqual([7, 8])
    expect(JSON.stringify(copie.page.props.workout)).not.toContain('_rowKey')

    // 2. La page part (`pagehide`) juste après une écriture acceptée : l'instantané n'attend ni l'anti-rebond, ni le repos.
    await cliquer('[dusk="complete-set-0-0"]')
    await attendre(() => sync.ecrituresEnCours() === 0)
    const pasDeRepos = []
    globalThis.requestIdleCallback = (rappel) => {
        pasDeRepos.push(rappel)
    }
    const depart = Date.now()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()
    delete globalThis.requestIdleCallback

    expect(pasDeRepos.length).toBeGreaterThan(0)
    expect((await premiereSerieGardee()).is_completed).toBe(false)
    expect(Date.now() - depart).toBeLessThan(delai)
    pasDeRepos.forEach((rappel) => rappel())

    // 3. La page se cache (un iPhone qu'on verrouille) : de même.
    await cliquer('[dusk="complete-set-0-0"]')
    await attendre(() => sync.ecrituresEnCours() === 0)
    const visibilite = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
    const cachee = Date.now()
    document.dispatchEvent(new Event('visibilitychange'))
    await ecriture.ecrituresFinies()
    visibilite.mockRestore()

    expect((await premiereSerieGardee()).is_completed).toBe(true)
    expect(Date.now() - cachee).toBeLessThan(delai)

    // 4. Sans réseau, la série ajoutée attend en file : rien n'est versé, et rien n'est noté.
    const noteAvantLaCoupure = noteDeLaSeance()
    reseau.serveur.enLigne = false
    await cliquer('[dusk="add-set-0"]')
    await attendre(() => sync.enAttente() === 1)
    await auDelaDeLAntiRebond()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect(rangees()).toBe(2)
    expect((await seanceGardee()).workout_lines[0].sets).toHaveLength(1)
    expect(noteDeLaSeance()).toBe(noteAvantLaCoupure)

    // Le réseau revient : la file se vide, et la série enregistrée entre dans la copie.
    reseau.serveur.enLigne = true
    window.dispatchEvent(new Event('online'))
    await attendre(() => sync.enAttente() === 0)

    await vi.waitFor(async () => expect((await seanceGardee()).workout_lines[0].sets).toHaveLength(2), {
        timeout: delai * 3,
    })
    expect(present('[dusk="set-unsynced-0-1"]')).toBe(false)

    // 5. Une suppression part, sa réponse tarde : la série a quitté l'écran, mais pas la copie, tant que le serveur n'a rien dit.
    await auDelaDeLAntiRebond()
    const libererLaSuppression = uneReponseRetenue(reseau.serveur)
    await cliquer('[dusk="remove-set-0-1"]')
    await attendre(() => sync.ecrituresEnCours() === 1)
    expect(rangees()).toBe(1)
    await auDelaDeLAntiRebond()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect((await seanceGardee()).workout_lines[0].sets).toHaveLength(2)

    libererLaSuppression()
    await attendre(() => sync.ecrituresEnCours() === 0)

    await vi.waitFor(async () => expect((await seanceGardee()).workout_lines[0].sets).toHaveLength(1), {
        timeout: delai * 3,
    })

    // 6. Sans réseau, la dernière série supprimée attend en file : la copie la garde jusqu'au vidage.
    await auDelaDeLAntiRebond()
    reseau.serveur.enLigne = false
    await cliquer('[dusk="remove-set-0-0"]')
    await attendre(() => sync.enAttente() === 1)
    expect(rangees()).toBe(0)
    await auDelaDeLAntiRebond()
    window.dispatchEvent(new Event('pagehide'))
    await ecriture.ecrituresFinies()

    expect((await seanceGardee()).workout_lines[0].sets.map((serie) => serie.id)).toEqual([3])

    reseau.serveur.enLigne = true
    window.dispatchEvent(new Event('online'))
    await attendre(() => sync.enAttente() === 0)

    await vi.waitFor(async () => expect((await seanceGardee()).workout_lines[0].sets).toEqual([]), {
        timeout: delai * 3,
    })

    // Plus rien ne change : l'anti-rebond qui reste ne verse rien de nouveau, et le test peut finir.
    await auDelaDeLAntiRebond()
    await ecriture.ecrituresFinies()
    await historiqueAuRepos()
}, 30_000)
