import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { COMPTE, retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    routeDeTest,
    seances,
    uneTentativeQuiTarde,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn(), reload: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
    // Les réglages se recalent à la sortie d'une copie hors ligne : la page du serveur ici (#2048).
    usePage: () => ({ props: {} }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * La personne se déconnecte dans un onglet, et accepte d'y perdre ses écritures
 * en attente ; un autre onglet reste ouvert sur la séance (#2050). Il apprend
 * le départ du compte par l'évènement `storage`, oublie sa copie de la file et
 * ne croit plus le compte connecté : rien n'entre plus en file à son nom.
 *
 * Une saisie faite ensuite sans réseau, dans cet onglet, ne pouvait donc être
 * ni envoyée ni gardée. L'échec réseau brut remontait pourtant à la saisie,
 * qui le lisait « en file » : elle effaçait le brouillon, gardait la valeur à
 * l'écran et ne disait rien. La valeur était perdue en silence, contre la
 * garantie de #1964.
 */

const MESSAGE_DE_LA_SAISIE = "Impossible d'enregistrer. La valeur précédente est rétablie."

const seance = () => ({
    id: 5,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            recommended_values: null,
            sets: [{ id: 3, weight: 80, reps: 5, is_completed: true, is_warmup: false }],
        },
    ],
})

/** L'avis qu'un autre onglet écrit quand le compte quitte l'appareil, tel que celui-ci le reçoit. */
const leCompteQuitteLAppareilDansUnAutreOnglet = () =>
    window.dispatchEvent(
        new StorageEvent('storage', {
            key: 'gym-tracker:compte-oublie-de-la-file',
            newValue: JSON.stringify({ compte: COMPTE }),
        }),
    )

/** Ce que la file et les brouillons gardent du poids saisi, sur tout l'appareil. */
const ceQuiResteDuPoids = () => JSON.stringify({ ...localStorage }).includes('92.5')

let departs

const surDepart = (evenement) => departs.push(evenement.detail)

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    globalThis.route = routeDeTest
    departs = []
    window.addEventListener('sync:compte-parti', surDepart)
})

afterEach(async () => {
    await seances.demonterToutes()
    window.removeEventListener('sync:compte-parti', surDepart)
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('une saisie dans un onglet dont le compte a quitté l’appareil dans un autre', () => {
    it('rétablit la valeur et le dit, au lieu de la croire en file', async () => {
        const page = await monterLaSeance(seance())

        leCompteQuitteLAppareilDansUnAutreOnglet()

        expect(page.sync.compte).toBeNull()
        expect(departs).toEqual([{ compte: COMPTE }])

        reseau.serveur.enLigne = false
        page.updateSet(page.ligne().sets[0], 'weight', '92.5')
        page.flushAllPendingUpdates()
        await flushPromises()

        // Ni envoyée, ni gardée : la valeur d'avant revient, et la page le dit.
        expect(page.ligne().sets[0].weight).toBe(80)
        expect(page.editError.value).toBe(MESSAGE_DE_LA_SAISIE)
        expect(fileDurable()).toEqual([])
        expect(page.sync.queue).toEqual([])
        expect(ceQuiResteDuPoids()).toBe(false)
    })

    it('rétablit de même l’écriture partie avant l’avis et tombée sans réseau après', async () => {
        const page = await monterLaSeance(seance())
        const couper = uneTentativeQuiTarde(reseau.serveur)

        page.updateSet(page.ligne().sets[0], 'weight', '92.5')
        page.flushAllPendingUpdates()
        await flushPromises()

        leCompteQuitteLAppareilDansUnAutreOnglet()
        await couper()
        await flushPromises()

        expect(page.ligne().sets[0].weight).toBe(80)
        expect(page.editError.value).toBe(MESSAGE_DE_LA_SAISIE)
        expect(fileDurable()).toEqual([])
        expect(ceQuiResteDuPoids()).toBe(false)
    })

    it('ne dit rien du départ d’un autre compte, et garde la saisie en file comme avant', async () => {
        const page = await monterLaSeance(seance())

        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'gym-tracker:compte-oublie-de-la-file',
                newValue: JSON.stringify({ compte: '2' }),
            }),
        )

        reseau.serveur.enLigne = false
        page.updateSet(page.ligne().sets[0], 'weight', '92.5')
        page.flushAllPendingUpdates()
        await flushPromises()

        expect(departs).toEqual([])
        expect(page.ligne().sets[0].weight).toBe(92.5)
        expect(page.editError.value).toBeNull()
        expect(fileDurable().map((entree) => [entree.compte, entree.url, entree.data])).toEqual([
            [COMPTE, '/api/v1/sets/3', { weight: 92.5 }],
        ])
    })
})
