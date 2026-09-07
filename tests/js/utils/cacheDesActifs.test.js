import { describe, it, expect, vi } from 'vitest'
import { ENTREES_MAX, NOM_DU_CACHE, estUnActif, servirDepuisLeCache } from '@/sw/cacheDesActifs'

const requete = (url, method = 'GET') => ({ url, method })

const cacheFactice = (contenu = new Map()) => ({
    contenu,
    match: vi.fn(async (r) => contenu.get(r.url) ?? undefined),
    put: vi.fn(async (r, reponse) => {
        contenu.delete(r.url)
        contenu.set(r.url, reponse)
    }),
    keys: vi.fn(async () => [...contenu.keys()].map((url) => requete(url))),
    delete: vi.fn(async (r) => contenu.delete(r.url)),
})

const magasin = (cache) => ({ open: vi.fn(async () => cache) })

/** `waitUntil` garde la promesse : le worker vit après la réponse, le test doit l'attendre. */
const evenement = (r) => {
    const travaux = []

    return { request: r, waitUntil: (promesse) => travaux.push(promesse), travaux }
}

const servir = async (event, magasinFactice, reseau) => {
    const reponse = await servirDepuisLeCache(event, magasinFactice, reseau)
    await Promise.all(event.travaux)

    return reponse
}

describe('estUnActif', () => {
    it('prend les actifs construits de notre origine', () => {
        expect(estUnActif(requete('https://fit.test/build/assets/main-abc.js'), 'https://fit.test')).toBe(true)
    })

    it.each([
        ['une écriture', requete('https://fit.test/build/assets/main-abc.js', 'POST'), 'https://fit.test'],
        ['une autre origine', requete('https://cdn.test/build/assets/main-abc.js'), 'https://fit.test'],
        ['une page', requete('https://fit.test/dashboard'), 'https://fit.test'],
        ['le worker lui-même', requete('https://fit.test/sw.js'), 'https://fit.test'],
    ])('laisse passer %s', (_, r, origine) => {
        expect(estUnActif(r, origine)).toBe(false)
    })
})

describe('servirDepuisLeCache', () => {
    it('rend ce qui est déjà en cache sans toucher au réseau', async () => {
        const cache = cacheFactice(new Map([['https://fit.test/build/assets/a.js', 'connu']]))
        const reseau = vi.fn()

        const reponse = await servir(evenement(requete('https://fit.test/build/assets/a.js')), magasin(cache), reseau)

        expect(reponse).toBe('connu')
        expect(reseau).not.toHaveBeenCalled()
    })

    it('va le chercher puis le garde', async () => {
        const cache = cacheFactice()
        const reponse = { ok: true, clone: () => 'copie' }

        const rendu = await servir(
            evenement(requete('https://fit.test/build/assets/b.js')),
            magasin(cache),
            vi.fn(async () => reponse),
        )

        expect(rendu).toBe(reponse)
        expect(cache.put).toHaveBeenCalledOnce()
        expect(cache.contenu.get('https://fit.test/build/assets/b.js')).toBe('copie')
    })

    /** Une réponse d'erreur mise en cache resterait servie jusqu'au prochain déploiement. */
    it('ne garde pas une réponse en erreur', async () => {
        const cache = cacheFactice()

        await servir(
            evenement(requete('https://fit.test/build/assets/c.js')),
            magasin(cache),
            vi.fn(async () => ({ ok: false, clone: () => 'copie' })),
        )

        expect(cache.put).not.toHaveBeenCalled()
    })

    it('coupe les plus anciennes au-delà de la centaine', async () => {
        const contenu = new Map(
            Array.from({ length: ENTREES_MAX }, (_, i) => [`https://fit.test/build/assets/${i}.js`, 'vieux']),
        )
        const cache = cacheFactice(contenu)

        await servir(
            evenement(requete('https://fit.test/build/assets/neuf.js')),
            magasin(cache),
            vi.fn(async () => ({ ok: true, clone: () => 'copie' })),
        )

        expect(cache.contenu.size).toBe(ENTREES_MAX)
        expect(cache.contenu.has('https://fit.test/build/assets/0.js')).toBe(false)
        expect(cache.contenu.has('https://fit.test/build/assets/neuf.js')).toBe(true)
    })

    it('ouvre toujours le même cache', async () => {
        const cache = cacheFactice()
        const magasinFactice = magasin(cache)

        await servir(
            evenement(requete('https://fit.test/build/assets/d.js')),
            magasinFactice,
            vi.fn(async () => ({ ok: true, clone: () => 'copie' })),
        )

        expect(magasinFactice.open).toHaveBeenCalledWith(NOM_DU_CACHE)
    })
})
