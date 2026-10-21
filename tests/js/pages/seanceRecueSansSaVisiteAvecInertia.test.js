import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { h } from 'vue'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
    rangees,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page n'a rien à montrer : la séance compte seule.
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
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))

/*
 * La séance créée en ligne dont la visite se perd (#2049), avec le vrai client
 * d'Inertia, le vrai démarrage, la vraie page de séance et la vraie file hors
 * ligne.
 *
 * Le serveur a créé la séance (201), mais la visite qui devait l'ouvrir
 * n'aboutit pas : la séance s'ouvre aussitôt, construite depuis la séance
 * reçue, sous l'adresse de sa clé, avec son identifiant réel et sans les
 * exercices que le serveur lui a donnés (ceux d'un modèle). Elle restait
 * ainsi, vide, le serveur revenu, jusqu'à un rechargement : un exercice ajouté
 * rejoignait ceux du modèle sans qu'on les voie. À la première réponse du
 * serveur, la page passe à la séance réelle, à la place de l'entrée
 * d'historique, et les montre.
 *
 * Le bouton qui démarre est un composant du test, à la place de la liste des
 * séances, qui appelle le vrai `useDemarrageDUneSeance`. La visite se perd par
 * une requête qui échoue (`onerror`, comme sans réseau), à la place de celle
 * du décor, rétablie ensuite.
 */

const pages = suivreLesPagesMontees()

/** Une visite d'Inertia qui n'atteint pas le serveur. */
class VisiteSansReseau {
    upload = {}
    status = 0
    responseText = ''

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

let visites
let usePage
let demarrage
let etatDuReseau

const BoutonQuiDemarre = {
    name: 'BoutonQuiDemarre',
    setup() {
        const { demarrer } = demarrage.useDemarrageDUneSeance()

        return () => h('button', { id: 'demarrer', onClick: () => demarrer() }, 'Démarrer')
    },
}

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
    ;({ visites } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: async (inertia) => {
            usePage = inertia.usePage
            // Dans le graphe de la page : le démarrage et l'état du réseau qu'elle partage avec la file.
            demarrage = await import('@/composables/useDemarrageDUneSeance')
            etatDuReseau = await import('@/composables/useEtatDuReseau')
        },
        ouverture: { adresse: '/workouts', selecteur: '#demarrer', etatDeLHistorique: null },
        composants: { 'Workouts/Index': BoutonQuiDemarre },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    await fermerLaListeDesSeances()
})

it('ouvre la séance reçue sous sa clé quand sa visite se perd, puis passe à la séance réelle et à ses exercices au retour du serveur', async () => {
    const RequeteDuDecor = globalThis.XMLHttpRequest

    // La création atteint le serveur (201) ; la visite de la séance, non.
    vi.stubGlobal('XMLHttpRequest', VisiteSansReseau)
    await cliquer('#demarrer')
    await vi.waitUntil(() => usePage().component === 'Workouts/Show')
    await flushPromises()

    const [creation] = reseau.serveur.requetes
    const cle = [...reseau.serveur.seances.values()][0]?.idempotency_key

    expect(creation).toMatchObject({ method: 'post', url: '/api/v1/workouts' })
    expect(cle).toMatch(/^[0-9A-Za-z-]{1,64}$/)
    expect(usePage().url).toBe(`/workouts/en-attente/${cle}`)
    expect(window.location.pathname).toBe(`/workouts/en-attente/${cle}`)
    expect(usePage().props.workout).toMatchObject({ id: 42, idempotency_key: cle, workout_lines: [] })
    expect(present('[dusk="exercise-card-0"]')).toBe(false)
    expect(fileDurable()).toEqual([])

    // Le serveur a donné ses exercices à la séance (ceux d'un modèle) : la page ne les a pas.
    reseau.serveur.lignes.set(71, { id: 71, workout_id: 42, exercise_id: 7 })
    reseau.serveur.series.set(301, { id: 301, workout_line_id: 71, weight: 60, reps: 8, is_completed: false })
    await flushPromises()

    expect(present('[dusk="exercise-card-0"]')).toBe(false)

    // Le serveur répond de nouveau : la page passe à la séance réelle, à la place de son entrée d'historique.
    vi.stubGlobal('XMLHttpRequest', RequeteDuDecor)
    visites.length = 0
    etatDuReseau.noterQueLeServeurRepond()

    await vi.waitUntil(() => usePage().url === '/workouts/42')
    await vi.waitUntil(() => present('[dusk="exercise-card-0"]'))
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect(window.location.pathname).toBe('/workouts/42')
    expect(rangees()).toBe(1)
    expect(reseau.serveur.requetes).toHaveLength(1)

    await historiqueAuRepos()
})
