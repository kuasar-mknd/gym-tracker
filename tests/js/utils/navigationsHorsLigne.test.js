import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import {
    DELAI_DE_LA_NAVIGATION_MS,
    MESSAGE_REPONSE_TARDIVE_ARRIVEE,
    MESSAGE_REPONSE_TARDIVE_RENVOYEE,
    PREFIXES_SANS_COQUILLE,
    URL_DE_LA_PAGE_HORS_LIGNE,
    activerLePrechargementDesNavigations,
    estUneNavigation,
    garderLePrechargement,
    naviguerAvecUnDelai,
    naviguerOuRetomber,
    naviguerSansCoquille,
    peutDireLaFinDeLaSession,
    sorteDeNavigation,
} from '@/sw/navigationsHorsLigne'

const requete = (mode, method = 'GET') => ({ url: 'https://fit.test/dashboard', mode, method })

/** Le réseau coupé, tel que `fetch` le dit : une erreur de type, pas une réponse. */
const reseauCoupe = () => Promise.reject(new TypeError('Failed to fetch'))

const pageHorsLigne = { page: 'hors ligne' }

describe('estUneNavigation', () => {
    it('prend un document demandé en GET', () => {
        expect(estUneNavigation(requete('navigate'))).toBe(true)
    })

    it.each([
        ['un formulaire posté', requete('navigate', 'POST')],
        ['une visite Inertia, qui part en XHR', requete('cors')],
        ['un actif', requete('no-cors')],
        ['un appel de même origine', requete('same-origin')],
    ])('laisse passer %s', (_, r) => {
        expect(estUneNavigation(r)).toBe(false)
    })
})

describe('naviguerOuRetomber', () => {
    it('rend la réponse que le navigateur a préchargée, sans refaire la requête', async () => {
        const prechargee = { page: 'accueil' }
        const reseau = vi.fn()
        const horsLigne = vi.fn()

        const reponse = await naviguerOuRetomber(
            { request: requete('navigate'), preloadResponse: Promise.resolve(prechargee) },
            horsLigne,
            reseau,
        )

        expect(reponse).toBe(prechargee)
        expect(reseau).not.toHaveBeenCalled()
        expect(horsLigne).not.toHaveBeenCalled()
    })

    it('va au réseau quand rien n’a été préchargé', async () => {
        const r = requete('navigate')
        const servie = { page: 'séance' }
        const reseau = vi.fn(async () => servie)

        const reponse = await naviguerOuRetomber(
            { request: r, preloadResponse: Promise.resolve(undefined) },
            vi.fn(),
            reseau,
        )

        expect(reponse).toBe(servie)
        expect(reseau).toHaveBeenCalledWith(r)
    })

    it('va au réseau là où le navigateur ne précharge pas du tout', async () => {
        const servie = { page: 'séance' }

        const reponse = await naviguerOuRetomber({ request: requete('navigate') }, vi.fn(), async () => servie)

        expect(reponse).toBe(servie)
    })

    it('rend une erreur du serveur telle quelle : seul le silence du réseau fait retomber', async () => {
        const erreur = { status: 502 }
        const horsLigne = vi.fn()

        const reponse = await naviguerOuRetomber({ request: requete('navigate') }, horsLigne, async () => erreur)

        expect(reponse).toBe(erreur)
        expect(horsLigne).not.toHaveBeenCalled()
    })

    it('sert la page « hors ligne » quand le préchargement échoue faute de réseau', async () => {
        const reseau = vi.fn()

        const reponse = await naviguerOuRetomber(
            { request: requete('navigate'), preloadResponse: reseauCoupe() },
            async () => pageHorsLigne,
            reseau,
        )

        expect(reponse).toBe(pageHorsLigne)
        expect(reseau).not.toHaveBeenCalled()
    })

    it('sert la page « hors ligne » quand le réseau ne répond pas', async () => {
        const reponse = await naviguerOuRetomber(
            { request: requete('navigate') },
            async () => pageHorsLigne,
            reseauCoupe,
        )

        expect(reponse).toBe(pageHorsLigne)
    })

    it('rend une erreur réseau si la page « hors ligne » manque au precache', async () => {
        const reponse = await naviguerOuRetomber({ request: requete('navigate') }, async () => undefined, reseauCoupe)

        // L'erreur du navigateur, comme avant : rien de pire.
        expect(reponse.type).toBe('error')
    })
})

describe('activerLePrechargementDesNavigations', () => {
    it('active le préchargement là où le navigateur l’offre', async () => {
        const enable = vi.fn(async () => undefined)

        await activerLePrechargementDesNavigations({ navigationPreload: { enable } })

        expect(enable).toHaveBeenCalledTimes(1)
    })

    it('se passe du préchargement là où il n’existe pas', async () => {
        await expect(activerLePrechargementDesNavigations({})).resolves.toBeUndefined()
    })
})

it('sert la page que la construction écrit dans public/build', () => {
    expect(URL_DE_LA_PAGE_HORS_LIGNE).toBe('/build/hors-ligne.html')
})

/*
 * Sans réseau, la réponse préchargée est rejetée : tenue par `waitUntil`
 * jusqu'à son règlement, et son rejet rattrapé (#2048).
 */
describe('garderLePrechargement', () => {
    it('tient le worker jusqu’au règlement de la réponse préchargée, et rattrape son rejet', async () => {
        const tenues = []
        const rejet = Promise.reject(new TypeError('Failed to fetch'))

        garderLePrechargement({ preloadResponse: rejet, waitUntil: (promesse) => tenues.push(promesse) })

        expect(tenues).toHaveLength(1)
        await expect(tenues[0]).resolves.toBeUndefined()
    })

    it('ne tient rien quand le navigateur ne précharge pas', () => {
        const waitUntil = vi.fn()

        garderLePrechargement({ waitUntil })

        expect(waitUntil).not.toHaveBeenCalled()
    })
})

/*
 * Trois sortes de navigations (#2048, partie F) : les pages de la salle
 * attendent huit secondes avant la page « hors ligne » ; le rappel de la
 * connexion sociale, le panneau, les tableaux de bord, les liens signés des
 * courriels et Dusk ne la reçoivent jamais ; les autres la reçoivent sans
 * délai, faute de réseau. Le vrai worker les joue dans navigationsDuWorker.test.js.
 */
describe('sorteDeNavigation', () => {
    const origine = 'https://fit.test'

    it.each(['/', '/dashboard', '/workouts/12', '/workouts?page=1', '/tools/warmup', '/dashboard#haut'])(
        'attend %s huit secondes',
        (chemin) => {
            expect(sorteDeNavigation(`${origine}${chemin}`, origine)).toBe('salle')
        },
    )

    it.each([
        '/auth/google/callback?code=abc',
        '/auth/apple/redirect',
        '/%61uth/google/callback',
        '/backoffice',
        '/backoffice/pulse',
        '/horizon/dashboard',
        '/telescope',
        '/verify-email/7/empreinte',
        '/reset-password/jeton',
        '/inscription/ouvrir/7/empreinte?expires=1&signature=abc',
        '/adresse/confirmer?compte=7&expires=1&signature=abc',
        '/_dusk/login/7',
    ])('ne donne jamais la page « hors ligne » à %s', (chemin) => {
        expect(sorteDeNavigation(`${origine}${chemin}`, origine)).toBe('sans-coquille')
    })

    it.each(['/stats', '/journal', '/workouts?page=2', '/workouts/12/edit', '/login', '/verify-email', '/%E0%A4%A'])(
        'laisse %s au réseau, puis à la page « hors ligne »',
        (chemin) => {
            expect(sorteDeNavigation(`${origine}${chemin}`, origine)).toBe('ordinaire')
        },
    )

    it('ne prend rien d’une autre origine', () => {
        expect(sorteDeNavigation('https://ailleurs.test/dashboard', origine)).toBe('sans-coquille')
        expect(sorteDeNavigation(undefined, origine)).toBe('sans-coquille')
    })

    it('écrit chaque début de chemin sur une ligne, comme le test PHP le lit', () => {
        expect(PREFIXES_SANS_COQUILLE.every((prefixe) => /^\/[a-z_/-]+$/.test(prefixe))).toBe(true)
        expect(Object.isFrozen(PREFIXES_SANS_COQUILLE)).toBe(true)
    })
})

describe('naviguerSansCoquille', () => {
    it('rend la réponse préchargée, ou celle du réseau', async () => {
        const prechargee = { page: 'rappel' }
        const servie = { page: 'panneau' }

        await expect(
            naviguerSansCoquille(
                { request: requete('navigate'), preloadResponse: Promise.resolve(prechargee) },
                vi.fn(),
            ),
        ).resolves.toBe(prechargee)
        await expect(naviguerSansCoquille({ request: requete('navigate') }, async () => servie)).resolves.toBe(servie)
    })

    it('laisse l’erreur du navigateur sans réseau', async () => {
        const reponse = await naviguerSansCoquille({ request: requete('navigate') }, reseauCoupe)

        expect(reponse.type).toBe('error')
    })
})

describe('peutDireLaFinDeLaSession', () => {
    it.each([
        ['une redirection opaque', { type: 'opaqueredirect', status: 0 }],
        ['une redirection', { type: 'basic', status: 302 }],
        ['un 401', { type: 'basic', status: 401 }],
    ])('retient %s', (_, reponse) => {
        expect(peutDireLaFinDeLaSession(reponse)).toBe(true)
    })

    it.each([
        ['une page', { type: 'basic', status: 200 }],
        ['une erreur du serveur', { type: 'basic', status: 503 }],
        ['un 403', { type: 'basic', status: 403 }],
        ['rien', undefined],
    ])('écarte %s', (_, reponse) => {
        expect(peutDireLaFinDeLaSession(reponse)).toBe(false)
    })
})

describe('naviguerAvecUnDelai', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    /** Un évènement de navigation, ses attentes relevées. */
    const evenement = (preloadResponse) => {
        const attentes = []

        return {
            request: requete('navigate'),
            preloadResponse,
            resultingClientId: 'page-ouverte',
            attentes,
            waitUntil: (promesse) => attentes.push(promesse),
        }
    }

    it('attend huit secondes par défaut', async () => {
        let repondre
        const e = evenement(new Promise((resoudre) => (repondre = resoudre)))
        const trouverLaPageHorsLigne = vi.fn(async (raison) => ({ raison }))
        const servie = naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne,
            allerChercher: vi.fn(),
            prevenirLaPage: vi.fn(),
        })

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_NAVIGATION_MS - 1)
        expect(trouverLaPageHorsLigne).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(1)

        await expect(servie).resolves.toEqual({ raison: 'delai' })
        repondre(undefined)
    })

    it('prévient la page ouverte d’une redirection arrivée après le délai, une fois', async () => {
        let repondre
        const e = evenement(new Promise((resoudre) => (repondre = resoudre)))
        const prevenirLaPage = vi.fn()
        const servie = naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async (raison) => ({ raison }),
            allerChercher: vi.fn(),
            prevenirLaPage,
            delai: 10,
        })

        await vi.advanceTimersByTimeAsync(10)
        await expect(servie).resolves.toEqual({ raison: 'delai' })
        expect(prevenirLaPage).not.toHaveBeenCalled()

        repondre(new Response(null, { status: 302, headers: { Location: '/login' } }))
        await Promise.all(e.attentes)

        expect(prevenirLaPage).toHaveBeenCalledExactlyOnceWith('page-ouverte', MESSAGE_REPONSE_TARDIVE_RENVOYEE)
    })

    /*
     * Le serveur était lent, pas absent : le délai de l'adresse se lève AVANT
     * que la page soit prévenue, pour que le rechargement qu'elle lance
     * attende le serveur au lieu de retomber sur la coquille.
     */
    it('lève le délai, puis prévient la page, d’une réponse réussie arrivée après le délai', async () => {
        let repondre
        const e = evenement(new Promise((resoudre) => (repondre = resoudre)))
        const ordre = []
        const leverLeDelai = vi.fn(() => ordre.push('levee'))
        const prevenirLaPage = vi.fn((_, type) => ordre.push(type))
        const servie = naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async (raison) => ({ raison }),
            allerChercher: vi.fn(),
            prevenirLaPage,
            leverLeDelai,
            delai: 10,
        })

        await vi.advanceTimersByTimeAsync(10)
        await expect(servie).resolves.toEqual({ raison: 'delai' })
        expect(leverLeDelai).not.toHaveBeenCalled()

        repondre(new Response('<!DOCTYPE html><title>Accueil</title>', { status: 200 }))
        await Promise.all(e.attentes)

        expect(prevenirLaPage).toHaveBeenCalledExactlyOnceWith('page-ouverte', MESSAGE_REPONSE_TARDIVE_ARRIVEE)
        expect(ordre).toEqual(['levee', MESSAGE_REPONSE_TARDIVE_ARRIVEE])
    })

    it.each([
        ['une erreur du serveur', 502],
        ['un 403', 403],
        ['un 404', 404],
    ])('ne lève rien et ne prévient de rien pour %s arrivée après le délai', async (_, statut) => {
        let repondre
        const e = evenement(new Promise((resoudre) => (repondre = resoudre)))
        const leverLeDelai = vi.fn()
        const prevenirLaPage = vi.fn()
        naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async (raison) => ({ raison }),
            allerChercher: vi.fn(),
            prevenirLaPage,
            leverLeDelai,
            delai: 10,
        })

        await vi.advanceTimersByTimeAsync(10)
        repondre(new Response('', { status: statut }))
        await Promise.all(e.attentes)

        expect(leverLeDelai).not.toHaveBeenCalled()
        expect(prevenirLaPage).not.toHaveBeenCalled()
    })

    it('ne lève rien d’une réponse réussie arrivée à temps', async () => {
        const e = evenement(Promise.resolve(new Response('à temps', { status: 200 })))
        const leverLeDelai = vi.fn()
        const prevenirLaPage = vi.fn()
        const reponse = await naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async (raison) => ({ raison }),
            allerChercher: vi.fn(),
            prevenirLaPage,
            leverLeDelai,
            delai: 10,
        })

        await Promise.all(e.attentes)

        await expect(reponse.text()).resolves.toBe('à temps')
        expect(leverLeDelai).not.toHaveBeenCalled()
        expect(prevenirLaPage).not.toHaveBeenCalled()
    })

    it('ne prévient de rien quand le réseau tombe après le délai', async () => {
        let couper
        const e = evenement(new Promise((_, rejeter) => (couper = rejeter)))
        const prevenirLaPage = vi.fn()
        naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async (raison) => ({ raison }),
            allerChercher: vi.fn(),
            prevenirLaPage,
            delai: 10,
        })

        await vi.advanceTimersByTimeAsync(10)
        couper(new TypeError('Failed to fetch'))

        await expect(Promise.all(e.attentes)).resolves.toBeDefined()
        expect(prevenirLaPage).not.toHaveBeenCalled()
    })

    it('rend l’erreur du navigateur si la page « hors ligne » manque au precache', async () => {
        const e = evenement(reseauCoupe())
        const reponse = await naviguerAvecUnDelai(e, {
            trouverLaPageHorsLigne: async () => undefined,
            allerChercher: vi.fn(),
            prevenirLaPage: vi.fn(),
        })

        expect(reponse.type).toBe('error')
    })
})
