import { describe, it, expect, vi } from 'vitest'
import {
    URL_DE_LA_PAGE_HORS_LIGNE,
    activerLePrechargementDesNavigations,
    estUneNavigation,
    naviguerOuRetomber,
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
