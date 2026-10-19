import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { COMPTE, horodatageRecent, ouvrirUnAppareil, poserLaPage } from './fileHorsLigne'

/*
 * La file hors ligne se partage entre les onglets de l'appareil (#2049).
 *
 * Chaque onglet lisait la file une fois, à son chargement, et la réécrivait
 * entière à chaque changement : deux onglets ouverts s'effaçaient leurs
 * écritures en attente, et vidaient tous deux les mêmes entrées. Ici chaque
 * onglet est une instance vivante de `SyncService`, sur le même
 * `localStorage`, et ce que l'un écrit est relayé aux autres comme le
 * navigateur le fait (`ouvrirUnAppareil`).
 */

const request = vi.fn()

vi.mock('@/Utils/http', () => ({ http: (...args) => request(...args) }))

const sansReseau = () => Promise.reject({ code: 'ERR_NETWORK', request: {} })

/** Les séries que nomment les entrées de la file, dans l'ordre du stockage. */
const seriesEnFile = () =>
    JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]').map((entree) =>
        Number(/\/sets\/(\d+)/.exec(entree.url)?.[1]),
    )

/** Les séries que nomment les refus gardés dans le stockage. */
const seriesRefusees = () =>
    JSON.parse(localStorage.getItem('offline_sync_failed') ?? '[]').map((entree) =>
        Number(/\/sets\/(\d+)/.exec(entree.url)?.[1]),
    )

/** Les écritures parties au serveur, `méthode adresse`. */
const envoyees = () => request.mock.calls.map(([config]) => `${config.method} ${config.url}`)

const enFile = (serie) => ({
    method: 'patch',
    url: `/api/v1/sets/${serie}`,
    data: { weight: 80 + serie },
    id: `q${serie}`,
    timestamp: horodatageRecent(),
    compte: COMPTE,
})

/**
 * Le `navigator.locks` d'un appareil, que ses onglets partagent : un verrou
 * tenu se refuse à qui demande `ifAvailable`, et se libère quand le rappel de
 * celui qui le tient a fini. `retenir()` retarde la libération jusqu'à ce que
 * le test la rende, pour jouer ce qui arrive juste avant.
 */
const verrousDeLAppareil = () => {
    const tenus = new Set()
    let retenue = null

    return {
        demandes: [],
        retenir() {
            let liberer

            retenue = new Promise((resoudre) => (liberer = resoudre))

            return liberer
        },
        async request(nom, options, rappel) {
            this.demandes.push({ nom, options })

            if (tenus.has(nom)) {
                return rappel(null)
            }

            tenus.add(nom)

            try {
                return await rappel({ name: nom, mode: 'exclusive' })
            } finally {
                await retenue
                tenus.delete(nom)
            }
        },
    }
}

let appareil

beforeEach(() => {
    localStorage.clear()
    request.mockReset()
    request.mockImplementation(sansReseau)
    appareil = ouvrirUnAppareil()
})

afterEach(async () => {
    appareil.fermer()
    delete navigator.locks
    localStorage.clear()
    poserLaPage(null)
})

describe('deux onglets sur la même file', () => {
    it('ne s’effacent plus leurs écritures : celle de B garde celle de A, et le rejeu de A garde celle de B', async () => {
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir()

        await expect(a.patch('/api/v1/sets/1', { weight: 81 })).rejects.toMatchObject({ isOffline: true })
        await expect(b.patch('/api/v1/sets/2', { weight: 82 })).rejects.toMatchObject({ isOffline: true })

        expect(seriesEnFile()).toEqual([1, 2])

        // Le réseau revient pour la série 1 seulement : A la rejoue, et la série 2 de B reste en file.
        request.mockImplementation((config) =>
            config.url === '/api/v1/sets/1' ? Promise.resolve({ data: { data: { id: 1 } } }) : sansReseau(),
        )
        await a.processQueue()

        expect(seriesEnFile()).toEqual([2])
        // B l'apprend par l'évènement `storage`, comme d'un autre onglet.
        expect(b.enAttente()).toBe(1)
        expect(a.enAttente()).toBe(1)
    })

    it('n’envoie chaque écriture qu’une fois quand les deux vident la file : un seul vidage sur l’appareil', async () => {
        const verrous = verrousDeLAppareil()
        Object.defineProperty(navigator, 'locks', { value: verrous, configurable: true })
        localStorage.setItem('offline_sync_queue', JSON.stringify([enFile(1), enFile(2)]))
        const reponses = []
        request.mockImplementation(() => new Promise((resoudre) => reponses.push(resoudre)))

        // A vide dès son chargement, et tient le verrou pendant que sa première écriture vole.
        const a = await appareil.ouvrir()
        await vi.waitUntil(() => reponses.length === 1)
        const b = await appareil.ouvrir()
        await b.processQueue()

        reponses[0]({ data: {} })
        await vi.waitUntil(() => reponses.length === 2)
        reponses[1]({ data: {} })
        await a.pending

        expect(envoyees()).toEqual(['patch /api/v1/sets/1', 'patch /api/v1/sets/2'])
        expect(seriesEnFile()).toEqual([])
        expect(b.enAttente()).toBe(0)
        expect(verrous.demandes.map(({ nom, options }) => [nom, options])).toContainEqual([
            'gym-tracker:vidage-de-la-file',
            { ifAvailable: true },
        ])
    })

    it('fait repartir l’onglet écarté par le verrou quand l’autre a vidé la file', async () => {
        const verrous = verrousDeLAppareil()
        Object.defineProperty(navigator, 'locks', { value: verrous, configurable: true })
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir()
        await Promise.all([a.pending, b.pending])
        localStorage.setItem('offline_sync_queue', JSON.stringify([enFile(1)]))
        request.mockResolvedValue({ data: {} })

        // A a vidé la file et tient encore le verrou quand B met une écriture en file, sans réseau.
        const liberer = verrous.retenir()
        const vidage = a.processQueue()
        await vi.waitUntil(() => seriesEnFile().length === 0)
        request.mockImplementationOnce(sansReseau)
        await expect(b.patch('/api/v1/sets/2', { weight: 82 })).rejects.toMatchObject({ isOffline: true })
        await b.processQueue()

        expect(seriesEnFile()).toEqual([2])

        // A rend le verrou : B, prévenu, vide sa file.
        liberer()
        await vidage
        await vi.waitUntil(() => seriesEnFile().length === 0)

        expect(envoyees()).toEqual(['patch /api/v1/sets/1', 'patch /api/v1/sets/2', 'patch /api/v1/sets/2'])
    })

    it('dit à l’autre onglet ce que son écriture a produit : la série qu’il met ensuite en file prend l’identifiant réel', async () => {
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir()
        const rejeux = []
        const surRejeu = (evenement) => rejeux.push(evenement.detail)
        window.addEventListener('sync:replayed', surRejeu)

        // B ajoute un exercice sans réseau ; A le rejoue quand le réseau revient.
        const { queueId } = await b
            .post('/api/v1/workout-lines', { workout_id: 5, exercise_id: 3 })
            .catch((erreur) => erreur)
        request.mockResolvedValue({ data: { data: { id: 77, workout_id: 5, exercise_id: 3 } } })
        await a.processQueue()
        await vi.waitUntil(() => rejeux.some((detail) => detail.relaye === true))
        window.removeEventListener('sync:replayed', surRejeu)

        expect(rejeux).toEqual([
            expect.objectContaining({ queueId, data: expect.objectContaining({ id: 77 }) }),
            expect.objectContaining({ queueId, relaye: true, data: expect.objectContaining({ id: 77 }) }),
        ])
        expect(b.rejeuxDepuisLaDerniereVisite).toBe(1)

        // B ajoute une série sous l'exercice qu'il croyait encore en file.
        request.mockImplementation(sansReseau)
        const serie = b.mettreEnFile({
            method: 'post',
            url: '/api/v1/sets',
            data: { workout_line_id: { enAttenteDe: queueId }, reps: 8 },
        })

        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))).toEqual([
            expect.objectContaining({ id: serie, data: { workout_line_id: 77, reps: 8 } }),
        ])
    })

    it('envoie avec l’identifiant réel une série rangée sous un exercice que cet onglet venait de rejouer', async () => {
        const a = await appareil.ouvrir()
        const refus = vi.fn()
        window.addEventListener('sync:failed', refus)
        const { queueId } = await a
            .post('/api/v1/workout-lines', { workout_id: 5, exercise_id: 3 })
            .catch((erreur) => erreur)
        request.mockResolvedValue({ data: { data: { id: 77 } } })
        await a.processQueue()

        // L'écriture d'un autre onglet, qui lisait encore l'exercice en file, arrive après son rejeu.
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                {
                    ...enFile(9),
                    method: 'post',
                    url: '/api/v1/sets',
                    data: { workout_line_id: { enAttenteDe: queueId }, reps: 8 },
                },
            ]),
        )
        request.mockClear()
        await a.processQueue()
        window.removeEventListener('sync:failed', refus)

        expect(request.mock.calls.map(([config]) => config.data)).toEqual([{ workout_line_id: 77, reps: 8 }])
        expect(refus).not.toHaveBeenCalled()
        expect(seriesEnFile()).toEqual([])
    })

    it('ne relaie l’annonce qu’à l’onglet du même compte', async () => {
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir({ compte: 2 })
        const rejeux = []
        const surRejeu = (evenement) => rejeux.push(evenement.detail)
        await expect(a.patch('/api/v1/sets/1', { weight: 81 })).rejects.toMatchObject({ isOffline: true })
        window.addEventListener('sync:replayed', surRejeu)

        request.mockResolvedValue({ data: { data: { id: 1 } } })
        await a.processQueue()
        // Une tâche plus tard, le canal a parlé : rien n'est relayé au compte 2.
        await new Promise((resoudre) => setTimeout(resoudre, 20))
        window.removeEventListener('sync:replayed', surRejeu)

        expect(rejeux.map((detail) => detail.relaye ?? false)).toEqual([false])
        expect(b.rejeuxDepuisLaDerniereVisite).toBe(0)
    })

    it('garde le refus que A a noté quand B en note un', async () => {
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir()

        await expect(a.patch('/api/v1/sets/1', { weight: 81 })).rejects.toMatchObject({ isOffline: true })
        request.mockRejectedValue({ response: { status: 422, data: {} } })
        await a.processQueue()

        request.mockImplementation(sansReseau)
        await expect(b.patch('/api/v1/sets/2', { weight: 82 })).rejects.toMatchObject({ isOffline: true })
        request.mockRejectedValue({ response: { status: 422, data: {} } })
        await b.processQueue()

        expect(seriesRefusees()).toEqual([1, 2])
        expect(a.failedRequests().map((refus) => refus.url)).toEqual(['/api/v1/sets/1', '/api/v1/sets/2'])
    })
})

describe('la file repart quand le serveur répond', () => {
    it('vide la file à la réponse suivante du serveur, la sonde comprise', async () => {
        const a = await appareil.ouvrir()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        await expect(a.post('/api/v1/sets', { workout_line_id: 3, reps: 8 })).rejects.toMatchObject({
            isOffline: true,
        })
        request.mockReset()
        request.mockResolvedValue({ data: { data: { id: 9 } } })

        appareil.dans(a, () => noterQueLeServeurRepond())
        await vi.waitUntil(() => request.mock.calls.length > 0)
        await a.pending

        expect(envoyees()).toEqual(['post /api/v1/sets'])
        expect(a.enAttente()).toBe(0)
    })

    it('ne relance pas la file sur les réponses de son propre vidage', async () => {
        const verrous = verrousDeLAppareil()
        Object.defineProperty(navigator, 'locks', { value: verrous, configurable: true })
        localStorage.setItem('offline_sync_queue', JSON.stringify([enFile(1), enFile(2)]))
        const a = await appareil.ouvrir()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        await a.pending
        let repondreALaSeconde

        // Chaque réponse du rejeu dit que le serveur répond, comme `http()` ; la seconde se fait attendre.
        request.mockImplementation((config) => {
            noterQueLeServeurRepond()

            return config.url === '/api/v1/sets/1'
                ? Promise.resolve({ data: {} })
                : new Promise((resoudre) => (repondreALaSeconde = resoudre))
        })
        const avant = verrous.demandes.length
        const vidage = a.processQueue()
        await vi.waitUntil(() => repondreALaSeconde !== undefined)
        await new Promise((resoudre) => setTimeout(resoudre, 20))
        repondreALaSeconde({ data: {} })
        await vidage
        await new Promise((resoudre) => setTimeout(resoudre, 20))

        expect(verrous.demandes).toHaveLength(avant + 1)
        expect(seriesEnFile()).toEqual([])
    })

    it('ne relance pas une file vide : deux onglets ne se renvoient pas la file vidée', async () => {
        const verrous = verrousDeLAppareil()
        Object.defineProperty(navigator, 'locks', { value: verrous, configurable: true })
        const a = await appareil.ouvrir()
        const b = await appareil.ouvrir()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        await Promise.all([a.pending, b.pending])
        const avant = verrous.demandes.length

        appareil.dans(b, () => noterQueLeServeurRepond())
        await new Promise((resoudre) => setTimeout(resoudre, 50))

        expect(verrous.demandes).toHaveLength(avant)
        expect(request).not.toHaveBeenCalled()
    })

    it('ne relance rien quand une erreur passagère a déjà programmé le prochain essai', async () => {
        const verrous = verrousDeLAppareil()
        Object.defineProperty(navigator, 'locks', { value: verrous, configurable: true })
        const a = await appareil.ouvrir()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        await expect(a.patch('/api/v1/sets/1', { weight: 81 })).rejects.toMatchObject({ isOffline: true })
        request.mockRejectedValue({ response: { status: 503, data: {}, headers: {} } })
        await a.processQueue()
        request.mockClear()
        const avant = verrous.demandes.length

        appareil.dans(a, () => noterQueLeServeurRepond())
        await new Promise((resoudre) => setTimeout(resoudre, 20))
        clearTimeout(a.relance)

        expect(verrous.demandes).toHaveLength(avant)
        expect(request).not.toHaveBeenCalled()
    })
})

describe('un stockage qui refuse d’écrire', () => {
    it('laisse à l’onglet ce qu’il n’a pas pu écrire, jusqu’à l’écriture suivante qui passe', async () => {
        const a = await appareil.ouvrir()
        const plein = vi.fn()
        window.addEventListener('sync:storage-full', plein)

        appareil.refuserLesEcritures('offline_sync_queue')
        await expect(a.patch('/api/v1/sets/1', { weight: 81 })).rejects.toMatchObject({ isOffline: true })
        await expect(a.patch('/api/v1/sets/2', { weight: 82 })).rejects.toMatchObject({ isOffline: true })
        window.removeEventListener('sync:storage-full', plein)

        expect(plein).toHaveBeenCalled()
        expect(localStorage.getItem('offline_sync_queue')).toBeNull()
        expect(a.queue.map((entree) => entree.url)).toEqual(['/api/v1/sets/1', '/api/v1/sets/2'])

        // Le stockage accepte de nouveau : le rejeu de la série 1 écrit ce que l'onglet gardait.
        appareil.refuserLesEcritures(null)
        request.mockImplementation((config) =>
            config.url === '/api/v1/sets/1' ? Promise.resolve({ data: {} }) : sansReseau(),
        )
        await a.processQueue()

        expect(seriesEnFile()).toEqual([2])
    })
})
