import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
    DELAI_D_UNE_DEMANDE_MS,
    installerLePrechauffage,
    interrompreLePrechauffage,
    noyauDuPrechauffage,
} from '@/Utils/prechauffage'
import { PAGES_GARDEES } from '@/Utils/pagesGardees'

/*
 * Le préchauffage des copies hors ligne (#2048, partie D), seul : un routeur,
 * une écriture et un magasin simulés, pour jouer sous des minuteries
 * simulées son délai de quinze secondes, et ce qui ne le fait jamais partir.
 * Le reste se joue avec le vrai client d'Inertia
 * (`prechauffageAvecInertia.test.js`).
 */

/** Une page d'un compte, telle qu'une réponse complète la donne. */
const pageDe = (url = '/tools', { compte = 7, encryptHistory = true, props = {} } = {}) => ({
    component: 'Tools/Index',
    url,
    version: 'v1',
    encryptHistory,
    props: { auth: { user: compte === null ? null : { id: compte, active_workout: null } }, ...props },
})

let ecouteurs
let purges
let ecriture
let magasin
let appels
let installes

/** Un routeur qui ne fait qu'émettre ce que le test lui dit. */
const routeur = {
    on: (nom, rappel) => {
        ecouteurs.set(nom, rappel)

        return () => ecouteurs.delete(nom)
    },
}

/** La réponse du serveur à une visite : Inertia émet `success`. */
const succes = (page) => ecouteurs.get('success')?.({ detail: { page } })

/** `fetch`, que rien ne fait répondre : seul son signal l'abandonne. */
const demander = (chemin, options) =>
    new Promise((_resoudre, rejeter) => {
        appels.push({ chemin, options })
        options.signal.addEventListener('abort', () => rejeter(new DOMException('aborted', 'AbortError')))
    })

/** Une fenêtre de test : visible, un document venu du réseau. */
const fenetreDe = ({ visibilite = 'visible', navigation = 'navigate', stockage = localStorage } = {}) => ({
    document: { visibilityState: visibilite, querySelector: () => null },
    performance: { getEntriesByType: () => [{ type: navigation }] },
    localStorage: stockage,
})

/** @param {object} [options] */
const installer = (options = {}) => {
    const installe = installerLePrechauffage({
        routeur,
        ecriture,
        magasin,
        fenetre: fenetreDe(),
        pageInitiale: null,
        demander,
        ...options,
    })

    installes.push(installe)

    return installe
}

/** Les microtâches de l'inventaire et de l'attente des écritures. */
const unTour = async () => {
    for (let tour = 0; tour < 10; tour++) {
        await Promise.resolve()
    }
}

beforeEach(() => {
    ecouteurs = new Map()
    purges = new Set()
    appels = []
    installes = []
    localStorage.clear()
    ecriture = { ecrituresFinies: () => Promise.resolve(), garder: vi.fn(), completer: vi.fn() }
    magasin = {
        inventaire: vi.fn(async () => ({
            possible: true,
            raison: null,
            empreinte: 'e1',
            copies: [],
            dernierRejeu: null,
        })),
        oublierLesCopies: vi.fn(async () => true),
        surUnePurge: (rappel) => {
            purges.add(rappel)

            return () => purges.delete(rappel)
        },
    }
})

afterEach(() => {
    installes.forEach((installe) => installe.desinstaller())
    vi.useRealTimers()
    vi.restoreAllMocks()
    localStorage.clear()
})

describe('le préchauffage, seul', () => {
    it('demande le noyau de salle, la séance en cours d’abord, jamais une séance close ni une adresse à paramètre', () => {
        const fixes = PAGES_GARDEES.filter((page) => !page.chemin.includes('{')).map((page) => page.chemin)
        const enCours = { ...pageDe(), props: { auth: { user: { id: 7, active_workout: { id: 12 } } } } }

        expect(noyauDuPrechauffage(pageDe())).toEqual(fixes)
        expect(noyauDuPrechauffage(enCours)).toEqual(['/workouts/12', ...fixes])
    })

    it('abandonne une demande restée sans réponse au bout de quinze secondes, et s’arrête là', async () => {
        vi.useFakeTimers()
        installer()

        succes(pageDe())
        await unTour()

        expect(appels.map((appel) => appel.chemin)).toEqual(['/dashboard'])

        await vi.advanceTimersByTimeAsync(DELAI_D_UNE_DEMANDE_MS - 1)
        expect(appels[0].options.signal.aborted).toBe(false)

        await vi.advanceTimersByTimeAsync(1)
        expect(appels[0].options.signal.aborted).toBe(true)

        await unTour()
        await vi.advanceTimersByTimeAsync(DELAI_D_UNE_DEMANDE_MS)

        expect(appels).toHaveLength(1)
        expect(ecriture.garder).not.toHaveBeenCalled()
    })

    it.each([
        ['une page sans compte', pageDe('/tools', { compte: null })],
        ['un historique en clair', pageDe('/tools', { encryptHistory: false })],
        ['une copie montrée hors ligne', pageDe('/tools', { props: { horsLigne: { copieDu: 1, raison: 'reseau' } } })],
        ['une page des parcours navigateur', pageDe('/tools', { props: { is_testing: true } })],
        ['une réponse sans page', null],
    ])('ne part pas pour %s', async (_cas, page) => {
        installer()

        succes(page)
        await unTour()

        expect(magasin.inventaire).not.toHaveBeenCalled()
        expect(appels).toEqual([])
    })

    it('ne part pas quand la page est cachée', async () => {
        installer({ fenetre: fenetreDe({ visibilite: 'hidden' }) })

        succes(pageDe())
        await unTour()

        expect(magasin.inventaire).not.toHaveBeenCalled()
        expect(appels).toEqual([])
    })

    it('ne part pas sans écriture des copies, ni quand le magasin ne peut rien écrire pour ce compte', async () => {
        installer({ ecriture: null })
        succes(pageDe())
        installes.pop().desinstaller()

        magasin.inventaire.mockResolvedValue({ possible: false, raison: 'sans-cle', empreinte: null, copies: [] })
        installer()
        succes(pageDe())
        await unTour()

        expect(magasin.inventaire).toHaveBeenCalledTimes(1)
        expect(appels).toEqual([])
    })

    it('part de la page initiale venue du réseau, et non d’un document rouvert par Retour ou Avance', async () => {
        installer({ pageInitiale: pageDe(), fenetre: fenetreDe({ navigation: 'back_forward' }) })
        await unTour()

        expect(appels).toEqual([])

        installer({ pageInitiale: pageDe() })
        await unTour()

        expect(appels.map((appel) => appel.chemin)).toEqual(['/dashboard'])
    })

    it('ne part pas deux fois à la fois, et une purge, ici ou dans un autre onglet, l’interrompt', async () => {
        const prechauffage = installer()

        succes(pageDe())
        succes(pageDe('/profile'))
        await unTour()

        expect(magasin.inventaire).toHaveBeenCalledTimes(1)
        expect(appels).toHaveLength(1)

        purges.forEach((prevenir) => prevenir())
        await prechauffage.fini()

        expect(appels[0].options.signal.aborted).toBe(true)
        expect(appels).toHaveLength(1)
    })

    it('interrompreLePrechauffage, ce qu’appelle la déconnexion, interrompt le préchauffage installé', async () => {
        const prechauffage = installer()

        succes(pageDe())
        await unTour()
        interrompreLePrechauffage()
        await prechauffage.fini()

        expect(appels[0].options.signal.aborted).toBe(true)
        expect(appels).toHaveLength(1)
    })

    it('ne lève jamais vers la page, même quand l’inventaire ou le stockage se refusent', async () => {
        const rejets = []
        const relais = (raison) => rejets.push(raison)
        process.on('unhandledRejection', relais)

        magasin.inventaire.mockRejectedValueOnce(new Error('IndexedDB refusé'))
        const prechauffage = installer()
        succes(pageDe())
        await prechauffage.fini()

        const refuse = { getItem: () => null, setItem: () => {} }
        vi.spyOn(refuse, 'getItem').mockImplementation(() => {
            throw new DOMException('refusé', 'SecurityError')
        })
        vi.spyOn(refuse, 'setItem').mockImplementation(() => {
            throw new DOMException('quota', 'QuotaExceededError')
        })
        installes.pop().desinstaller()
        installer({ fenetre: fenetreDe({ stockage: refuse }) })
        succes(pageDe())
        await unTour()
        await new Promise((resoudre) => setTimeout(resoudre, 0))
        process.off('unhandledRejection', relais)

        expect(rejets).toEqual([])
        expect(appels.map((appel) => appel.chemin)).toEqual(['/dashboard'])
    })

    it('désinstallé, ne part plus et n’écoute plus', async () => {
        installer().desinstaller()
        installes.pop()

        expect(ecouteurs.size).toBe(0)
        expect(purges.size).toBe(0)
        expect(appels).toEqual([])
    })
})
