import { it, expect, vi, beforeAll, afterEach } from 'vitest'

import { creerUnFauxServeur, fileDurable, routeDeTest } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    DEVELOPPE_COUCHE,
    SQUAT,
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))
vi.mock('@/composables/useAbonnementPush', () => ({ rapprocherLAbonnementPush: vi.fn() }))

// Le vrai layout, pour ses toasts. La modale d'ajout se réduit au choix qu'elle émet : le développé couché.
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
 * Une séance démarrée sans réseau que le serveur refuse au rejeu (#2049) : une
 * autre séance était déjà en cours. Avec le vrai client d'Inertia, la vraie
 * page de séance, son vrai layout et la vraie file.
 *
 * Le refus emporte ce qui la nommait : l'exercice et la série ajoutés sans
 * réseau sont refusés faute de parent, sans partir. La page passe en lecture
 * seule et dit pourquoi ; le layout annonce le refus une fois, avec ce qu'il
 * emporte. La page de séance ne disait rien de la séance, mais une erreur par
 * série et par exercice : « Un élément de la séance n'a pas pu être
 * enregistré », pour une séance qui n'existait pas.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance-refusee'

/** Les toasts du layout, leur message seul, sans les glyphes de l'icône et du bouton de fermeture. */
const toasts = () =>
    [...document.querySelectorAll('[role="alert"][aria-live]')].map((toast) =>
        toast.querySelector('p').textContent.trim(),
    )

let sync
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
    ;({ sync } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: (inertia) => {
            usePage = inertia.usePage
            // Le layout lit aussi la route courante (`route().current(…)`) : aucune ici.
            globalThis.route = (nom, parametres) =>
                nom === undefined ? { current: () => false } : (routeDeTest(nom, parametres) ?? `/${nom}`)
        },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    await fermerLaListeDesSeances()
})

it('passe la séance refusée en lecture seule, et le layout dit le refus une fois, sans un mot par série', async () => {
    reseau.serveur.enLigne = false

    const queueId = sync.mettreEnFile({
        method: 'post',
        url: '/api/v1/workouts',
        data: { started_at: '2026-10-19T08:15:00.000Z' },
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
    await cliquer('#ajouter-le-developpe-couche')
    await vi.waitUntil(() => fileDurable().length === 2)
    await cliquer('[dusk="add-set-0"]')
    await vi.waitUntil(() => fileDurable().length === 3)

    expect(toasts()).toEqual([])

    // Le réseau revient : le serveur refuse la séance, une autre étant en cours.
    reseau.serveur.enLigne = true
    reseau.serveur.imposer.push(async () => {
        throw {
            response: {
                status: 409,
                data: { message: 'Une séance est déjà en cours.', raison: 'seance-deja-en-cours', seance: { id: 9 } },
            },
        }
    })
    await sync.processQueue()
    await vi.waitUntil(() => toasts().length > 0)
    await historiqueAuRepos()

    // Seule la séance est partie : l'exercice et la série sont refusés avec elle, sans partir.
    expect(reseau.serveur.resume()).toEqual(['post /api/v1/workouts {"started_at":"2026-10-19T08:15:00.000Z"}'])
    expect(fileDurable()).toEqual([])

    // Un seul toast : le refus de la séance, et ce qu'il emporte.
    expect(toasts()).toEqual([
        "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours. 2 modifications de cette séance ne partiront pas.",
    ])
    expect(present('[dusk="set-edit-error"]')).toBe(false)

    // La page le dit, en lecture seule, avec le chemin de l'accueil.
    expect(document.querySelector('[dusk="etat-de-la-seance-en-attente"]').textContent).toContain(
        "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours.",
    )
    expect(document.querySelector('[dusk="seance-en-attente-accueil"]').getAttribute('href')).toBe('/dashboard')
    expect(present('[dusk="add-set-0"]')).toBe(false)
    expect(present('[dusk="add-exercise-existing"]')).toBe(false)
    expect(window.location.pathname).toBe(`/workouts/en-attente/${CLE}`)

    // Le refus reste parmi les refus, noté comme dit, avec ce qu'il emporte.
    const refus = JSON.parse(localStorage.getItem('offline_sync_failed') ?? '[]')

    expect(
        refus.map(({ id, raison, parentRefuse, parent, annonce }) => ({ id, raison, parentRefuse, parent, annonce })),
    ).toEqual([
        { id: queueId, raison: 'seance-deja-en-cours', parentRefuse: undefined, parent: undefined, annonce: true },
        expect.objectContaining({ parentRefuse: true, parent: queueId }),
        expect.objectContaining({ parentRefuse: true }),
    ])

    await historiqueAuRepos()
})
