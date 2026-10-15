import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import { creerUnFauxServeur, monterLaSeance, routeDeTest, seances } from './seanceHorsLigne'

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
 * `router.reload` : la page reçoit la séance que le serveur rend, à la réponse
 * de la visite. Une réponse arrive dans une tâche à elle, jamais pendant
 * l'annonce du vidage : la page a déjà adopté, en microtâches, ce qu'elle
 * suivait (#2048). Le test attend cette réponse par `reponseDuRechargement()`.
 */
const recharger = vi.fn((page) =>
    new Promise((resolve) => setTimeout(resolve)).then(() => page.rafraichir(seanceDuServeur())),
)

/** La réponse du dernier rechargement demandé, appliquée à la page. */
const reponseDuRechargement = () => recharger.mock.results.at(-1)?.value

/** Sans réseau : l'exercice 7, une série corrigée à 60 kg, puis la page meurt. */
const unExerciceHorsLigneAvantUnRechargement = async () => {
    const page = await monterLaSeance(seanceVide())
    reseau.serveur.enLigne = false

    page.addExercise(7)
    page.addSet(page.ligne().id)
    await flushPromises()
    page.saisieTerminee(page.ligne().sets[0], 'weight', '60')
    await flushPromises()

    page.wrapper.unmount()
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

afterEach(async () => {
    await seances.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('la séance rechargée pendant que la file attend', () => {
    it('montre l’exercice et la série que le vidage a créés, sans action de la personne', async () => {
        await unExerciceHorsLigneAvantUnRechargement()

        // Le réseau est revenu : le vidage du chargement part avant le montage.
        reseau.serveur.enLigne = true
        const page = await monterLaSeance(seanceVide(), { recharger })

        expect(recharger).toHaveBeenCalledTimes(1)
        await reponseDuRechargement()
        expect(ecran(page)).toEqual([{ ligne: 70, series: [100] }])
        expect(page.ligne().sets[0]).toMatchObject({ weight: 60 })
    })

    it('se recharge aussi quand le vidage aboutit après le montage, une fois la file vide', async () => {
        await unExerciceHorsLigneAvantUnRechargement()

        // Toujours sans réseau au montage : la file attend, et la page la montre (#2048).
        const page = await monterLaSeance(seanceVide(), { recharger })
        expect(recharger).not.toHaveBeenCalled()
        expect(page.ligne().sets[0]).toMatchObject({ weight: 60 })

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
        await reponseDuRechargement()
        expect(ecran(page)).toEqual([{ ligne: 70, series: [100] }])
        expect(page.unsyncedSetIds.value.size).toBe(0)
    })

    /*
     * Le refus s'annonçait avant que l'écriture refusée quitte la file : à cet
     * instant, la file n'était pas vide, et rien ne suivait son retrait.
     * L'exercice créé restait absent de l'écran, et la personne le rajoutait.
     */
    it('se recharge aussi quand la dernière écriture d’avant le montage est refusée', async () => {
        await unExerciceHorsLigneAvantUnRechargement()
        const page = await monterLaSeance(seanceVide(), { recharger })

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
        await reponseDuRechargement()

        /*
         * La série refusée reste à l'écran, marquée, comme celle qu'on vient
         * d'ajouter quand le serveur la refuse au vidage : la page la montrait
         * depuis son ouverture (#2048), et la faire disparaître effacerait une
         * saisie sans un mot. Le rapport a dit laquelle.
         */
        const [serieRefusee] = page.ligne().sets
        expect(ecran(page)).toEqual([{ ligne: 70, series: [serieRefusee.id] }])
        expect(page.unsyncedSetIds.value.has(String(serieRefusee.id))).toBe(true)
        expect(page.editError.value).toBe("La série 1 de « Squat » n'a pas pu être enregistrée.")
    })

    it('se recharge aussi quand la dernière écriture est refusée sans partir, son exercice refusé', async () => {
        const exercices = [
            { id: 7, name: 'Squat', type: 'strength' },
            { id: 8, name: 'Développé couché', type: 'strength' },
        ]
        const avant = await monterLaSeance(seanceVide(), { exercices })
        reseau.serveur.enLigne = false

        avant.addExercise(7)
        avant.addExercise(8)
        avant.addSet(avant.ligne(1).id)
        await flushPromises()
        avant.wrapper.unmount()

        const page = await monterLaSeance(seanceVide(), { exercices, recharger })

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
        await reponseDuRechargement()

        // L'exercice refusé et sa série restent à l'écran, la série marquée, comme refusés depuis la page.
        const refusee = page.ligne(1)
        expect(ecran(page)).toEqual([
            { ligne: 70, series: [] },
            { ligne: refusee.id, series: [refusee.sets[0].id] },
        ])
        expect(page.unsyncedSetIds.value.has(String(refusee.sets[0].id))).toBe(true)
    })

    /*
     * Ce que la page écrit elle-même, elle le suit déjà, et l'écran tient sa
     * valeur : la recharger reprendrait au serveur une valeur en pleine saisie.
     */
    it('ne se recharge pas pour les écritures que la page a faites elle-même', async () => {
        const page = await monterLaSeance(seanceVide(), { recharger })
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
