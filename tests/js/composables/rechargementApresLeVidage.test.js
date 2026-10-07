import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, monterLaSeance, routeDeTest } from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * Au rechargement, le serveur rend la séance AVANT que la file se vide. Ce que
 * le vidage créait ensuite — l'exercice ajouté hors ligne, ses séries — arrivait
 * au serveur, mais la page rechargée ne le montrait pas : la personne voyait son
 * exercice disparu, et le rajoutait (#1960, #1962).
 */

const seanceVide = () => ({ id: 5, workout_lines: [] })

/** La séance telle que le faux serveur la rendrait maintenant. */
const seanceDuServeur = () => ({
    id: 5,
    workout_lines: [...reseau.serveur.lignes.values()].map((ligne) => ({
        ...ligne,
        exercise: { id: ligne.exercise_id, name: 'Squat', type: 'strength' },
        sets: [...reseau.serveur.series.values()].filter((serie) => serie.workout_line_id === ligne.id),
    })),
})

/**
 * Les pages encore montées. Démontées après chaque test : leurs écouteurs
 * entendraient sinon le vidage du suivant, et l'échec d'un test ferait tomber
 * ceux qui le suivent.
 */
const montees = new Set()

const monter = async (seance, options) => {
    const page = await monterLaSeance(seance, options)
    montees.add(page.wrapper)

    return page
}

const demonter = (page) => {
    montees.delete(page.wrapper)
    page.wrapper.unmount()
}

/** `router.reload` : la page reçoit la séance que le serveur rend à cet instant. */
const recharger = vi.fn((page) => page.rafraichir(seanceDuServeur()))

/** Sans réseau : l'exercice 7, une série corrigée à 60 kg, puis la page meurt. */
const unExerciceHorsLigneAvantUnRechargement = async () => {
    const page = await monter(seanceVide())
    reseau.serveur.enLigne = false

    page.addExercise(7)
    page.addSet(page.ligne().id)
    await flushPromises()
    page.saisieTerminee(page.ligne().sets[0], 'weight', '60')
    await flushPromises()

    demonter(page)
}

/** Le refus d'une écriture, tel que le serveur le rend : la série hors des bornes, par exemple. */
const unRefus = async () => {
    throw { response: { status: 422, data: { message: 'weight' } } }
}

/** Ce que l'écran montre : chaque exercice et les identifiants de ses séries. */
const ecran = (page) =>
    page.localWorkout.value.workout_lines.map((ligne) => ({
        ligne: ligne.id,
        series: ligne.sets.map((serie) => serie.id),
    }))

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur()
    globalThis.route = routeDeTest
    recharger.mockClear()
})

afterEach(() => {
    montees.forEach((wrapper) => wrapper.unmount())
    montees.clear()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('la séance rechargée pendant que la file attend', () => {
    it('montre l’exercice et la série que le vidage a créés, sans action de la personne', async () => {
        await unExerciceHorsLigneAvantUnRechargement()

        // Le réseau est revenu : le vidage du chargement part avant le montage.
        reseau.serveur.enLigne = true
        const page = await monter(seanceVide(), { recharger })

        expect(recharger).toHaveBeenCalledTimes(1)
        expect(ecran(page)).toEqual([{ ligne: 70, series: [100] }])
        expect(page.ligne().sets[0]).toMatchObject({ weight: 60 })
    })

    it('se recharge aussi quand le vidage aboutit après le montage, une fois la file vide', async () => {
        await unExerciceHorsLigneAvantUnRechargement()

        // Toujours sans réseau au montage : la file attend, rien n'a changé.
        const page = await monter(seanceVide(), { recharger })
        expect(recharger).not.toHaveBeenCalled()

        // Le réseau ne revient que le temps de créer l'exercice.
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(async (config) => {
            reseau.serveur.enLigne = false
            reseau.serveur.lignes.set(70, { id: 70, ...config.data, sets: [], recommended_values: null })

            return { data: { data: { id: 70, ...config.data } } }
        })
        await page.sync.processQueue()
        await flushPromises()

        // La série attend encore : pas de rechargement à moitié.
        expect(page.sync.enAttente()).toBe(1)
        expect(recharger).not.toHaveBeenCalled()

        reseau.serveur.enLigne = true
        await page.sync.processQueue()
        await flushPromises()

        expect(recharger).toHaveBeenCalledTimes(1)
        expect(ecran(page)).toEqual([{ ligne: 70, series: [100] }])
    })

    /*
     * Le refus s'annonçait avant que l'écriture refusée quitte la file : à cet
     * instant, la file n'était pas vide, et rien ne suivait son retrait.
     * L'exercice créé restait absent de l'écran, et la personne le rajoutait.
     */
    it('se recharge aussi quand la dernière écriture d’avant le montage est refusée', async () => {
        await unExerciceHorsLigneAvantUnRechargement()
        const page = await monter(seanceVide(), { recharger })

        // L'exercice passe, sa série est refusée.
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(null, unRefus)
        await page.sync.processQueue()
        await flushPromises()

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/workout-lines',
            'post /api/v1/sets',
        ])
        expect(page.sync.enAttente()).toBe(0)
        expect(recharger).toHaveBeenCalledTimes(1)
        expect(ecran(page)).toEqual([{ ligne: 70, series: [] }])
    })

    it('se recharge aussi quand la dernière écriture est refusée sans partir, son exercice refusé', async () => {
        const exercices = [
            { id: 7, name: 'Squat', type: 'strength' },
            { id: 8, name: 'Développé couché', type: 'strength' },
        ]
        const avant = await monter(seanceVide(), { exercices })
        reseau.serveur.enLigne = false

        avant.addExercise(7)
        avant.addExercise(8)
        avant.addSet(avant.ligne(1).id)
        await flushPromises()
        demonter(avant)

        const page = await monter(seanceVide(), { exercices, recharger })

        // Le premier exercice passe, le second est refusé : sa série ne part pas.
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(null, unRefus)
        await page.sync.processQueue()
        await flushPromises()

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/workout-lines',
            'post /api/v1/workout-lines',
        ])
        expect(page.sync.failedRequests().map((refus) => refus.url)).toEqual(['/api/v1/workout-lines', '/api/v1/sets'])
        expect(page.sync.enAttente()).toBe(0)
        expect(recharger).toHaveBeenCalledTimes(1)
        expect(ecran(page)).toEqual([{ ligne: 70, series: [] }])
    })

    /*
     * Ce que la page écrit elle-même, elle le suit déjà, et l'écran tient sa
     * valeur : la recharger reprendrait au serveur une valeur en pleine saisie.
     */
    it('ne se recharge pas pour les écritures que la page a faites elle-même', async () => {
        const page = await monter(seanceVide(), { recharger })
        reseau.serveur.enLigne = false

        page.addExercise(7)
        page.addSet(page.ligne().id)
        await flushPromises()

        reseau.serveur.enLigne = true
        await page.sync.processQueue()
        await flushPromises()

        expect(page.sync.enAttente()).toBe(0)
        expect(recharger).not.toHaveBeenCalled()
        expect(ecran(page)).toEqual([{ ligne: 70, series: [100] }])
    })
})
