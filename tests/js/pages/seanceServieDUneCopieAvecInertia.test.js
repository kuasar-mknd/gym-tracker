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
    uneReponseRetenue,
} from './seanceDansLHistorique'
import { heureCourte } from '@/Utils/date'

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
 * La séance servie depuis sa copie hors ligne, dans l'application ouverte
 * (#2048, partie E), avec le vrai client d'Inertia, la vraie page de séance,
 * le vrai service des copies, la vraie écriture des copies et le magasin
 * chiffré sur une base en mémoire.
 *
 * La copie de la séance ne prend son instantané que quand tout est
 * enregistré. Une série acceptée en ligne, puis une saisie partie sans
 * réponse au moment de quitter la page : l'instantané n'a pas pu se prendre,
 * et la copie reste celle de la réponse. Servie sans réseau, la séance dit
 * que des séries enregistrées après l'heure de sa copie n'y apparaissent
 * pas, mais qu'elles sont bien enregistrées : la personne ne les refait pas.
 *
 * Le décor vient de `seanceDansLHistorique.js` : un fichier, un test.
 */

const pages = suivreLesPagesMontees()

/** La clé du témoin, posée comme le serveur la pose : 32 octets en base64url. */
const CLE = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA'

let visiter
let sync
let magasin
let ecriture
let fabriqueDeLaBase
let routeur

/** Ce que la note des écritures acceptées dit de la séance 5. */
const noteDeLaSeance = () =>
    JSON.parse(localStorage.getItem('gym-tracker:ecritures-acceptees') ?? 'null')?.seances?.['5'] ?? null

/** Une condition, le temps que la saisie parte après son propre délai. */
const attendre = (condition) => vi.waitUntil(condition, { timeout: 4000 })

/** Une visite d'Inertia sans réseau : la requête échoue aussitôt, comme hors ligne. */
class RequeteSansReseau {
    upload = {}
    status = 0

    open() {}

    setRequestHeader() {}

    getAllResponseHeaders() {
        return ''
    }

    abort() {
        this.onabort?.()
    }

    send() {
        queueMicrotask(() => this.onerror?.())
    }
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
        // Comme `main.js` : les visites hors ligne, puis l'écriture des copies, avant Inertia.
        avantInertia: async ({ router }) => {
            const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
            const { installerLEcritureDesCopies } = await import('@/Utils/ecritureDesCopies')
            const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')

            routeur = router
            magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabriqueDeLaBase) })
            installerLesVisitesHorsLigne({ routeur: router, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })
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

it('servie d’une copie plus ancienne qu’une série acceptée, la séance dit que cette série est bien enregistrée', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await ecriture.ecrituresFinies()

    const dateDeLaCopie = (await magasin.lireUneCopie('/workouts/5')).datesDesProps.workout

    // Une série acceptée en ligne : la note le dit aussitôt.
    await new Promise((resoudre) => setTimeout(resoudre, 5))
    await saisir('[dusk="weight-input-0-0"]', '85')
    await attendre(() => noteDeLaSeance() !== null && sync.ecrituresEnCours() === 0)

    expect(noteDeLaSeance()).toBeGreaterThan(dateDeLaCopie)

    // Une autre saisie part au départ de la page, sans réponse : l'instantané ne se prend pas.
    const liberer = uneReponseRetenue(reseau.serveur)
    await saisir('[dusk="reps-input-0-0"]', '6')
    await visiter('/workouts', '#liste-des-seances')
    await attendre(() => sync.ecrituresEnCours() === 1)
    await ecriture.ecrituresFinies()

    expect((await magasin.lireUneCopie('/workouts/5')).page.props.workout.workout_lines[0].sets[0].weight).toBe(80)

    liberer()
    await attendre(() => sync.ecrituresEnCours() === 0)

    // Sans réseau, le lien vers la séance ouvre sa copie, qui le dit.
    vi.stubGlobal('XMLHttpRequest', RequeteSansReseau)
    routeur.visit('/workouts/5')
    await attendre(() => present('[dusk="series-hors-de-la-copie"]'))

    const note = document.querySelector('[dusk="series-hors-de-la-copie"]')

    expect(note.getAttribute('role')).toBe('status')
    expect(note.lastElementChild.textContent.replace(/\s+/g, ' ').trim()).toBe(
        `Des séries enregistrées après ${heureCourte(new Date(dateDeLaCopie))} n'apparaissent pas ici : elles sont bien enregistrées.`,
    )
    expect(document.querySelector('[dusk="weight-input-0-0"]').value).toBe('80')

    await historiqueAuRepos()
})
