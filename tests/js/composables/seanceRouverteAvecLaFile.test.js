import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    routeDeTest,
    seances,
    uneReponsePerdue,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn(), reload: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/*
 * La séance rouverte pendant que la file attend encore (#2048).
 *
 * Le rechargement rend la séance du serveur, qui ne sait rien de ce qui attend
 * en file. La page la prenait telle quelle : la série ajoutée et cochée sans
 * réseau disparaissait de l'écran, sans `cloud_off`, jusqu'au retour du réseau,
 * alors que la file la gardait. La personne la croyait perdue, et la refaisait.
 *
 * Chaque cas monte la séance, agit sans réseau, puis la remonte comme un
 * rechargement : un service de synchronisation neuf, relu depuis le stockage, et
 * la séance telle que le serveur la rend, toujours sans réseau pour les écritures.
 */

const EXERCICES = [
    { id: 7, name: 'Squat', type: 'strength' },
    { id: 8, name: 'Développé couché', type: 'strength' },
]

const seance = () => ({
    id: 5,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            recommended_values: null,
            sets: [
                { id: 3, weight: 80, reps: 5, is_completed: true, is_warmup: false },
                { id: 4, weight: 85, reps: 3, is_completed: false, is_warmup: false },
            ],
        },
    ],
})

/**
 * La séance telle que le serveur la rend maintenant, avec ce qu'il a en base :
 * chaque série porte la clé d'idempotence de la création qui l'a produite,
 * comme la page de séance la reçoit.
 */
const seanceDuServeur = () => {
    const cles = new Map(
        [...reseau.serveur.creees]
            .filter(([cle]) => cle.startsWith('/api/v1/sets '))
            .map(([cle, id]) => [id, cle.slice('/api/v1/sets '.length)]),
    )
    const [ligne] = seance().workout_lines

    return {
        ...seance(),
        workout_lines: [
            {
                ...ligne,
                sets: [...reseau.serveur.series.values()]
                    .filter((serie) => serie.workout_line_id === 1)
                    .sort((a, b) => a.id - b.id)
                    .map(({ workout_line_id: _ligne, ...serie }) => ({
                        is_warmup: false,
                        ...serie,
                        idempotency_key: cles.get(serie.id) ?? null,
                    })),
            },
        ],
    }
}

/** Ce que l'écran montre : chaque exercice et les identifiants de ses séries. */
const ecran = (page) =>
    page.localWorkout.value.workout_lines.map((ligne) => [ligne.id, ligne.sets.map((serie) => serie.id)])

/** La page meurt et revient, le réseau toujours absent : la séance est celle que le serveur rend. */
const recharger = async (page) => {
    page.wrapper.unmount()

    return monterLaSeance(seance(), { exercices: EXERCICES })
}

/** Le réseau revient : la file se vide, et la page apprend ce qu'elle est devenue. */
const leReseauRevient = async (page) => {
    reseau.serveur.enLigne = true
    await page.sync.processQueue()
    await flushPromises()
}

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur({
        series: [
            { id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true },
            { id: 4, workout_line_id: 1, weight: 85, reps: 3, is_completed: false },
        ],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    globalThis.route = routeDeTest
})

afterEach(async () => {
    await seances.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('une série ajoutée et cochée sans réseau, puis la séance rechargée', () => {
    it('reste à l’écran, cochée et marquée, et la saisie suivante rejoint la même entrée', async () => {
        const avant = await monterLaSeance(seance(), { exercices: EXERCICES })
        reseau.serveur.enLigne = false

        avant.addSet(1)
        await flushPromises()
        const ajoutee = avant.ligne().sets.at(-1)
        avant.saisieTerminee(ajoutee, 'weight', '82.5')
        avant.saisieTerminee(ajoutee, 'reps', '7')
        await avant.toggleSetCompletion(ajoutee)
        await flushPromises()

        const [entree] = fileDurable()
        const page = await recharger(avant)
        const reprise = page.ligne().sets.at(-1)

        expect(ecran(page)).toEqual([[1, [3, 4, `temp-q${entree.id}`]]])
        expect(reprise).toMatchObject({ weight: 82.5, reps: 7, is_completed: true })
        expect(page.unsyncedSetIds.value.has(reprise.id)).toBe(true)

        // Corrigée après le rechargement : elle se fond dans la même entrée, qui reste seule.
        page.saisieTerminee(reprise, 'reps', '8')
        await page.toggleSetCompletion(reprise)
        await page.toggleSetCompletion(reprise)
        await flushPromises()

        expect(fileDurable()).toHaveLength(1)
        expect(fileDurable()[0]).toMatchObject({
            id: entree.id,
            data: { workout_line_id: 1, is_completed: true, weight: 82.5, reps: 8 },
        })

        await leReseauRevient(page)

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":1,"is_completed":true,"weight":82.5,"reps":8}',
        ])
        expect([...reseau.serveur.series.values()].filter((serie) => serie.weight === 82.5)).toHaveLength(1)
        expect(reprise).toMatchObject({ id: 100, weight: 82.5, reps: 8, is_completed: true })
        expect(ecran(page)).toEqual([[1, [3, 4, 100]]])
        expect(page.unsyncedSetIds.value.size).toBe(0)
        expect(fileDurable()).toEqual([])
    })

    /*
     * Sa création a été tentée sans réponse : rien ne dit qu'elle n'a pas
     * atteint le serveur. La file la garde pour l'annuler là-bas (#1960) ; la
     * page ne la remontre pas.
     */
    it('se retire après le rechargement, et ne revient ni à l’écran ni en base', async () => {
        const avant = await monterLaSeance(seance(), { exercices: EXERCICES })
        reseau.serveur.enLigne = false
        avant.addSet(1)
        await flushPromises()

        const page = await recharger(avant)
        page.removeSet(page.ligne().sets.at(-1).id)
        await flushPromises()

        expect(ecran(page)).toEqual([[1, [3, 4]]])
        expect(fileDurable()).toEqual([expect.objectContaining({ aAnnuler: '/api/v1/sets/__produit__' })])

        const encore = await recharger(page)

        expect(ecran(encore)).toEqual([[1, [3, 4]]])

        await leReseauRevient(encore)

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/sets',
            'delete /api/v1/sets/100',
        ])
        expect([...reseau.serveur.series.keys()]).toEqual([3, 4])
        expect(ecran(encore)).toEqual([[1, [3, 4]]])
    })
})

/*
 * Le cas d'un réseau faible : la création atteint le serveur, qui l'écrit, et
 * la réponse se perd. La file la garde, tentée ; la séance rechargée, elle, la
 * porte déjà. Superposée une seconde fois, la série se montrait deux fois, et
 * retirer l'une des deux supprimait au vidage celle du serveur.
 */
describe('une série arrivée au serveur sans que la réponse revienne, puis la séance rechargée', () => {
    it('ne se montre qu’une fois, et ce qui a été saisi depuis la rejoint au vidage', async () => {
        const avant = await monterLaSeance(seance(), { exercices: EXERCICES })
        uneReponsePerdue(reseau.serveur)

        avant.addSet(1)
        await flushPromises()
        avant.saisieTerminee(avant.ligne().sets.at(-1), 'reps', '9')
        await flushPromises()

        expect(fileDurable()).toEqual([
            expect.objectContaining({ tentee: true, data: expect.objectContaining({ reps: 9 }) }),
        ])

        avant.wrapper.unmount()
        const page = await monterLaSeance(seanceDuServeur(), { exercices: EXERCICES })

        expect(ecran(page)).toEqual([[1, [3, 4, 100]]])
        expect(page.ligne().sets.at(-1)).toMatchObject({ id: 100, weight: 85, reps: 9 })

        await leReseauRevient(page)

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":85,"reps":3}',
            'post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":85,"reps":9}',
            'patch /api/v1/sets/100 {"reps":9}',
        ])
        expect([...reseau.serveur.series.keys()]).toEqual([3, 4, 100])
        expect(reseau.serveur.series.get(100)).toMatchObject({ weight: 85, reps: 9 })
        expect(ecran(page)).toEqual([[1, [3, 4, 100]]])
    })
})

describe('un exercice ajouté sans réseau, puis la séance rechargée', () => {
    it('reste à l’écran avec sa série, et une série ajoutée ensuite se range derrière lui', async () => {
        const avant = await monterLaSeance(seance(), { exercices: EXERCICES })
        reseau.serveur.enLigne = false
        avant.addExercise(8)
        await flushPromises()
        avant.addSet(avant.ligne(1).id)
        await flushPromises()

        const [creationDeLigne, creationDeSerie] = fileDurable()
        const page = await recharger(avant)

        expect(ecran(page)).toEqual([
            [1, [3, 4]],
            [`temp-q${creationDeLigne.id}`, [`temp-q${creationDeSerie.id}`]],
        ])
        expect(page.ligne(1).exercise).toMatchObject({ id: 8, name: 'Développé couché' })

        page.addSet(page.ligne(1).id)
        await flushPromises()

        expect(fileDurable().map((entree) => entree.data.workout_line_id)).toEqual([
            undefined,
            { enAttenteDe: creationDeLigne.id },
            { enAttenteDe: creationDeLigne.id },
        ])
        expect(page.ligne(1).sets.every((serie) => page.unsyncedSetIds.value.has(String(serie.id)))).toBe(true)

        await leReseauRevient(page)

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/workout-lines',
            'post /api/v1/sets',
            'post /api/v1/sets',
        ])
        expect(ecran(page)).toEqual([
            [1, [3, 4]],
            [70, [100, 101]],
        ])
        expect([...reseau.serveur.series.values()].filter((serie) => serie.workout_line_id === 70)).toHaveLength(2)
        expect(page.unsyncedSetIds.value.size).toBe(0)
    })
})

describe('une série du serveur modifiée, supprimée ou déplacée sans réseau, puis la séance rechargée', () => {
    it('garde à l’écran la valeur, le retrait et l’ordre qui attendent en file', async () => {
        const avant = await monterLaSeance(seance(), { exercices: EXERCICES })
        reseau.serveur.enLigne = false

        avant.saisieTerminee(avant.ligne().sets[0], 'weight', '90')
        await avant.flushPendingUpdates(3)
        avant.deplacerSerie(avant.ligne(), 0, 1)
        await flushPromises()
        avant.removeSet(4)
        await flushPromises()

        const page = await recharger(avant)

        expect(ecran(page)).toEqual([[1, [3]]])
        expect(page.ligne().sets[0]).toMatchObject({ id: 3, weight: 90 })

        await leReseauRevient(page)

        expect(reseau.serveur.resume()).toEqual([
            'patch /api/v1/sets/3 {"weight":90}',
            'patch /api/v1/workout-lines/1/set-order {"sets":[4,3]}',
            'delete /api/v1/sets/4',
        ])
    })
})
