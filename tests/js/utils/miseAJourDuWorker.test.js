import { describe, it, expect, vi, beforeEach, afterAll } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { flushPromises } from '@vue/test-utils'
import { UNE_HEURE_MS, inscrireLeWorker, nouvelleVersionPrete, rechargerMaintenant } from '@/Utils/miseAJourDuWorker'

/*
 * Le `registerSW` que l'application reçoit vraiment.
 *
 * `virtual:pwa-register` n'existe qu'à la construction : vite-plugin-pwa y
 * recopie son `client/build/register.js` en remplaçant des constantes, dont le
 * mode, tiré du `registerType` de vite.config.js (dist/index.js du paquet). Le
 * mode décide de tout : en « autoUpdate », le paquet recharge la page à
 * l'activation d'un nouveau worker, sauf si `onNeedReload` lui est passé, et
 * n'appelle jamais `onNeedRefresh`. Le test refait la même substitution, avec
 * le mode lu dans vite.config.js, et ne simule que Workbox : un changement de
 * mode ou de paquet se verrait ici.
 */
const racine = resolve(__dirname, '../../..')

const modeDeLaConstruction = () => {
    const configuration = readFileSync(resolve(racine, 'vite.config.js'), 'utf-8')

    return configuration.match(/registerType:\s*'([^']+)'/)[1]
}

/** Les écouteurs que le paquet pose sur Workbox, par évènement. */
const ecouteursDeWorkbox = {}
const inscription = { update: vi.fn() }
/** Ce que `register()` rend : l'inscription, ou rien quand le navigateur refuse. */
let inscriptionRendue = inscription

class WorkboxSimule {
    addEventListener(type, rappel) {
        ;(ecouteursDeWorkbox[type] ??= []).push(rappel)
    }

    register() {
        return Promise.resolve(inscriptionRendue)
    }

    messageSkipWaiting() {}
}

const registerSWConstruit = () => {
    const source = readFileSync(resolve(racine, 'node_modules/vite-plugin-pwa/dist/client/build/register.js'), 'utf-8')
    const construit = source
        .replace(/__SW__/g, '/sw.js')
        .replace('__SCOPE__', '/')
        .replace('__SW_AUTO_UPDATE__', `${modeDeLaConstruction() === 'autoUpdate'}`)
        .replace('__SW_SELF_DESTROYING__', 'false')
        .replace('__TYPE__', 'classic')
        .replace('import("workbox-window")', 'importerWorkbox()')
        .replace(/export\s*\{\s*registerSW\s*\};?/, 'return registerSW;')

    expect(construit).not.toMatch(/__SW_AUTO_UPDATE__|import\("workbox-window"\)|export \{/)

    return new Function('importerWorkbox', construit)(async () => ({ Workbox: WorkboxSimule }))
}

/** Ce que le navigateur annonce quand un worker prend la main. */
const activer = (evenement) => ecouteursDeWorkbox.activated.forEach((rappel) => rappel(evenement))

/** Un routeur qui retient ses écouteurs, comme celui d'Inertia les pose. */
const routeurSimule = () => {
    const ecouteurs = {}

    return { ecouteurs, on: (type, rappel) => (ecouteurs[type] = rappel) }
}

/** Une visite telle qu'Inertia la décrit à `before`, avec ses valeurs par défaut. */
const visite = (options = {}) => ({
    url: new URL('http://localhost/stats'),
    method: 'get',
    replace: false,
    preserveState: false,
    only: [],
    except: [],
    reset: [],
    prefetch: false,
    async: false,
    ...options,
})

const fenetreSimulee = () => ({ location: { reload: vi.fn(), assign: vi.fn(), replace: vi.fn() } })

/*
 * Le rechargement que ferait le paquet passe par la vraie `window.location`.
 * jsdom ne sait pas naviguer : elle est remplacée le temps du test.
 */
const locationOrigine = Object.getOwnPropertyDescriptor(window, 'location')
const rechargementDuPaquet = vi.fn()

const inscrire = async () => {
    const routeur = routeurSimule()
    const fenetre = fenetreSimulee()
    inscrireLeWorker({ registerSW: registerSWConstruit(), routeur, fenetre })
    await flushPromises()

    return { routeur, fenetre }
}

beforeEach(() => {
    Object.keys(ecouteursDeWorkbox).forEach((type) => delete ecouteursDeWorkbox[type])
    inscription.update.mockClear()
    inscriptionRendue = inscription
    rechargementDuPaquet.mockClear()
    nouvelleVersionPrete.value = false
    Object.defineProperty(navigator, 'serviceWorker', { value: {}, configurable: true })
    Object.defineProperty(window, 'location', {
        value: { ...window.location, reload: rechargementDuPaquet },
        configurable: true,
    })
})

afterAll(() => {
    Object.defineProperty(window, 'location', locationOrigine)
    delete navigator.serviceWorker
    vi.useRealTimers()
})

describe('un nouveau worker qui prend la main', () => {
    it('se construit en mode automatique, le seul où le paquet rechargeait de lui-même', async () => {
        await inscrire()

        // Le mode « prompt » écouterait `waiting` ; celui-ci écoute `activated`.
        expect(modeDeLaConstruction()).toBe('autoUpdate')
        expect(Object.keys(ecouteursDeWorkbox).sort()).toEqual(['activated', 'installed'])
    })

    it.each([
        ['un déploiement trouvé par cette page', { isUpdate: true }],
        ['un déploiement trouvé par un autre onglet', { isUpdate: false, isExternal: true }],
    ])('ne recharge pas la page ouverte : %s', async (_, evenement) => {
        const { fenetre } = await inscrire()

        activer(evenement)

        // Le minuteur de repos, celui d'intervalles et la saisie d'une modale
        // vivent dans la page : la recharger les perdait.
        expect(rechargementDuPaquet).not.toHaveBeenCalled()
        expect(fenetre.location.reload).not.toHaveBeenCalled()
        expect(nouvelleVersionPrete.value).toBe(true)
    })

    it('ne propose rien à la première installation', async () => {
        await inscrire()

        activer({ isUpdate: false, isExternal: false })

        expect(nouvelleVersionPrete.value).toBe(false)
    })
})

describe('la navigation suivante', () => {
    it('se fait en entier, sur la nouvelle version, à la place de la visite d’Inertia', async () => {
        const { routeur, fenetre } = await inscrire()
        activer({ isUpdate: true })

        const resultat = routeur.ecouteurs.before({ detail: { visit: visite() } })

        expect(resultat).toBe(false)
        expect(fenetre.location.assign).toHaveBeenCalledWith('http://localhost/stats')
    })

    it('remplace l’entrée de l’historique quand la visite le demandait', async () => {
        const { routeur, fenetre } = await inscrire()
        activer({ isUpdate: true })

        routeur.ecouteurs.before({ detail: { visit: visite({ replace: true }) } })

        expect(fenetre.location.replace).toHaveBeenCalledWith('http://localhost/stats')
        expect(fenetre.location.assign).not.toHaveBeenCalled()
    })

    it('reste une visite d’Inertia tant qu’aucune nouvelle version n’est prête', async () => {
        const { routeur, fenetre } = await inscrire()

        expect(routeur.ecouteurs.before({ detail: { visit: visite() } })).toBeUndefined()
        expect(fenetre.location.assign).not.toHaveBeenCalled()
    })

    it.each([
        ['une écriture', { method: 'post' }],
        ['une visite qui garde l’état de la page, comme un filtre', { preserveState: true }],
        ['un rechargement partiel', { only: ['journals'] }],
        ['un rechargement qui écarte des props', { except: ['stats'] }],
        ['un rechargement qui remet des props à zéro', { reset: ['items'] }],
        ['un préchargement', { prefetch: true }],
        ['une interrogation en arrière-plan', { async: true }],
    ])('laisse passer %s, qui ne quitte pas la page', async (_, options) => {
        const { routeur, fenetre } = await inscrire()
        activer({ isUpdate: true })

        expect(routeur.ecouteurs.before({ detail: { visit: visite(options) } })).toBeUndefined()
        expect(fenetre.location.assign).not.toHaveBeenCalled()
        expect(fenetre.location.replace).not.toHaveBeenCalled()
    })
})

describe('la recherche d’une nouvelle version', () => {
    it('regarde toutes les heures, une PWA installée n’étant jamais fermée', async () => {
        vi.useFakeTimers()
        await inscrire()

        vi.advanceTimersByTime(UNE_HEURE_MS)

        expect(inscription.update).toHaveBeenCalledTimes(1)
        vi.useRealTimers()
    })

    it('ne programme rien quand le navigateur n’a pas inscrit le worker', async () => {
        vi.useFakeTimers()
        inscriptionRendue = undefined
        await inscrire()

        expect(vi.getTimerCount()).toBe(0)
        vi.useRealTimers()
    })
})

it('recharge sur le geste de l’utilisateur', () => {
    const fenetre = fenetreSimulee()

    rechargerMaintenant(fenetre)

    expect(fenetre.location.reload).toHaveBeenCalledTimes(1)
})
