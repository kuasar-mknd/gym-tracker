import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    rechargerQuandLeReseauRevient,
    routeDeTest,
    uneTentativeQuiTarde,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * Une série ajoutée sans réseau partait dans la file, et sa création se
 * résolvait à « rien » pour toute la vie de la page (#1960). La rangée gardait
 * son identifiant provisoire : sa saisie et sa coche ne partaient jamais, sa
 * suppression ne l'effaçait qu'à l'écran, le moindre rafraîchissement des props
 * l'affichait deux fois, et son exercice ne se réordonnait plus.
 *
 * C'est le parcours ordinaire dans une salle sans réseau : ajouter une série,
 * corriger les répétitions, cocher.
 */

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

const serveurDeLaSeance = () =>
    creerUnFauxServeur({ series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }] })

/** Ajoute une série sans réseau et laisse sa création partir dans la file. */
const ajouterHorsLigne = async (page) => {
    reseau.serveur.enLigne = false
    page.addSet(1)
    await flushPromises()

    return page.ligne().sets.at(-1)
}

/** Le réseau revient : la file se vide, et la page apprend ce qu'elle est devenue. */
const leReseauRevient = async (page) => {
    reseau.serveur.enLigne = true
    await page.sync.processQueue()
    await flushPromises()
}

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = serveurDeLaSeance()
    globalThis.route = routeDeTest
})

afterEach(() => {
    vi.useRealTimers()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('une série créée hors ligne', () => {
    it('arrive au serveur corrigée et cochée, sans rechargement', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)

        page.saisieTerminee(serie, 'reps', '3')
        await page.toggleSetCompletion(serie)
        await flushPromises()

        // La correction et la coche sont dans la file, pas seulement à l'écran.
        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
        ])

        await leReseauRevient(page)

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":1,"is_completed":true,"weight":80,"reps":3}',
        ])
        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 3, is_completed: true })
        expect(serie).toMatchObject({ id: 100, reps: 3, is_completed: true })
        expect(page.unsyncedSetIds.value.size).toBe(0)
    })

    it('envoie après sa création ce qui a été saisi et coché pendant qu’elle volait', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)

        let repondre
        const creation = new Promise((resolve) => (repondre = resolve))
        reseau.serveur.imposer.push(async (config) => {
            await creation

            return { data: { data: { id: 100, ...config.data, created_at: 'c', updated_at: 'u' } } }
        })

        reseau.serveur.enLigne = true
        const vidage = page.sync.processQueue()
        await flushPromises()

        // La création est partie : ce qui suit ne peut plus s'y fondre.
        page.saisieTerminee(serie, 'weight', '82.5')
        page.toggleSetCompletion(serie)
        repondre()
        await vidage
        await flushPromises()

        expect(reseau.serveur.resume()).toEqual([
            'post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":80,"reps":5}',
            'patch /api/v1/sets/100 {"weight":82.5}',
            'patch /api/v1/sets/100 {"is_completed":true}',
        ])
        expect(serie).toMatchObject({ id: 100, weight: 82.5, is_completed: true })
    })

    /*
     * La première tentative vole encore quand on saisit, coche ou supprime :
     * elle n'a pas encore d'entrée où se fondre. Ce qui est fait pendant ce
     * temps attendait le vidage en mémoire seulement, et un rechargement le
     * perdait.
     */
    it('emporte dans la file ce qui a été saisi et coché pendant que sa première tentative échouait', async () => {
        const page = await monterLaSeance(seance())
        const couper = uneTentativeQuiTarde(reseau.serveur)

        page.addSet(1)
        await flushPromises()
        const serie = page.ligne().sets.at(-1)

        page.saisieTerminee(serie, 'reps', '3')
        page.toggleSetCompletion(serie)
        await couper()

        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: true, weight: 80, reps: 3 },
        ])

        const rechargee = await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 3, is_completed: true })
        expect(reseau.serveur.series.size).toBe(2)
        expect(rechargee.queue).toEqual([])
    })

    it('garde dans la file la dernière coche, quand la première a été faite pendant sa première tentative', async () => {
        const page = await monterLaSeance(seance())
        const couper = uneTentativeQuiTarde(reseau.serveur)

        page.addSet(1)
        await flushPromises()
        const serie = page.ligne().sets.at(-1)

        // Cochée pendant que la tentative vole, décochée une fois la création en file.
        page.toggleSetCompletion(serie)
        await couper()
        page.toggleSetCompletion(serie)
        await flushPromises()

        expect(serie.is_completed).toBe(false)
        expect(fileDurable().map((entree) => entree.data)).toEqual([
            { workout_line_id: 1, is_completed: false, weight: 80, reps: 5 },
        ])

        await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect(reseau.serveur.series.get(100)).toMatchObject({ is_completed: false })
    })

    it('sort de la file quand on la supprime pendant que sa première tentative échouait, rechargement compris', async () => {
        const page = await monterLaSeance(seance())
        const couper = uneTentativeQuiTarde(reseau.serveur)

        page.addSet(1)
        await flushPromises()

        page.removeSet(page.ligne().sets.at(-1).id)
        await couper()

        expect(fileDurable()).toEqual([])

        const rechargee = await rechargerQuandLeReseauRevient(page, reseau.serveur)

        expect([...reseau.serveur.series.keys()]).toEqual([3])
        expect(rechargee.queue).toEqual([])
    })

    it('n’existe pas sur le serveur quand elle est supprimée avant le retour du réseau', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)

        page.removeSet(serie.id)
        await flushPromises()

        expect(fileDurable()).toEqual([])

        await leReseauRevient(page)

        expect(reseau.serveur.resume()).toEqual([])
        expect(page.ligne().sets.map((s) => s.id)).toEqual([3])
    })

    it('est supprimée du serveur quand on la retire pendant que sa création vole', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)

        let repondre
        const creation = new Promise((resolve) => (repondre = resolve))
        reseau.serveur.imposer.push(async (config) => {
            await creation
            reseau.serveur.series.set(100, { id: 100, ...config.data })

            return { data: { data: { id: 100, ...config.data } } }
        })

        reseau.serveur.enLigne = true
        const vidage = page.sync.processQueue()
        await flushPromises()

        page.removeSet(serie.id)
        repondre()
        await vidage
        await flushPromises()

        expect(reseau.serveur.resume().map((requete) => requete.split(' {')[0])).toEqual([
            'post /api/v1/sets',
            'delete /api/v1/sets/100',
        ])
        expect(reseau.serveur.series.has(100)).toBe(false)
    })

    it('ne s’affiche qu’une fois après le vidage, même quand les props se rafraîchissent, et n’est plus marquée', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)
        expect(page.unsyncedSetIds.value.has(String(serie.id))).toBe(true)

        await leReseauRevient(page)

        // Renommer la séance, tirer pour rafraîchir : la copie du serveur revient.
        page.rafraichir({
            ...seance(),
            workout_lines: [{ ...seance().workout_lines[0], sets: [...reseau.serveur.series.values()] }],
        })

        expect(page.ligne().sets.map((s) => s.id)).toEqual([3, 100])
        expect(page.unsyncedSetIds.value.size).toBe(0)
    })

    it('se réordonne avec les autres séries de l’exercice après le vidage', async () => {
        const page = await monterLaSeance(seance())
        await ajouterHorsLigne(page)
        await leReseauRevient(page)

        page.deplacerSerie(page.ligne(), 1, 0)
        await flushPromises()

        expect(reseau.serveur.resume().at(-1)).toBe('patch /api/v1/workout-lines/1/set-order {"sets":[100,3]}')
        expect(page.ligne().sets.map((s) => s.id)).toEqual([100, 3])
        expect(page.editError.value).toBeNull()
    })

    it('attend le verdict du vidage, et reste à l’écran, marquée, quand le serveur la refuse', async () => {
        const page = await monterLaSeance(seance())
        const serie = await ajouterHorsLigne(page)
        reseau.serveur.imposer.push(() => Promise.reject({ response: { status: 422 } }))

        // Sa création n'est pas perdue : elle attend le vidage.
        expect(page.pendingIds.isPending(serie.id)).toBe(true)

        await leReseauRevient(page)

        expect(page.pendingIds.isPending(serie.id)).toBe(false)

        expect(page.ligne().sets.map((s) => s.id)).toEqual([3, serie.id])
        expect(page.unsyncedSetIds.value.has(String(serie.id))).toBe(true)
        expect(page.editError.value).toBe("La série 2 de « Squat » n'a pas pu être enregistrée.")
    })
})
