import { describe, expect, it } from 'vitest'

import { creerDesCachesEnMemoire } from './cachesEnMemoire'
import { CACHE_DE_LA_CONSTRUCTION_PRECEDENTE, NOM_DU_CACHE } from '@/sw/cacheDesActifs'
import {
    CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE,
    basculerLaConstructionPrecedente,
    mettreDeCoteLaConstructionPrecedente,
} from '@/sw/constructionPrecedente'

/*
 * La construction précédente, gardée le temps d'une mise à jour (#2048,
 * partie E) : l'installation met de côté les actifs du precache que le nouveau
 * manifeste n'a plus, l'activation vide `construction-precedente` et les y
 * range. Les deux constructions successives, avec le vrai worker et le vrai
 * Workbox, sont dans `tests/js/app/deuxConstructions.test.js`.
 */

const ORIGINE = 'https://salle.example.org'
const PRECACHE = `workbox-precache-v2-${ORIGINE}/`

/** Une réponse d'actif, au corps reconnaissable. */
const actif = (corps) => new Response(corps, { status: 200, headers: { 'Content-Type': 'text/javascript' } })

/**
 * Un precache tel que Workbox le range : les actifs construits sous leur
 * adresse, la page « hors ligne » et une icône sous leur révision.
 */
const precacheDeLaPremiereConstruction = async (caches) => {
    const precache = await caches.open(PRECACHE)

    await precache.put(`${ORIGINE}/build/assets/main-Ancien.js`, actif('entrée ancienne'))
    await precache.put(`${ORIGINE}/build/assets/Show-Ancien.js`, actif('séance ancienne'))
    await precache.put(`${ORIGINE}/build/assets/vue-core-Commun.js`, actif('vue'))
    await precache.put(`${ORIGINE}/build/assets/archivo-latin-Police.woff2?__WB_REVISION__=a1`, actif('police'))
    await precache.put(`${ORIGINE}/build/hors-ligne.html?__WB_REVISION__=b1`, actif('page hors ligne'))
    await precache.put(`${ORIGINE}/logo.svg?__WB_REVISION__=c1`, actif('logo'))

    return precache
}

/** Le manifeste de la construction suivante : l'entrée et la séance changent, Vue et la police restent. */
const manifesteDeLaSuivante = [
    { url: '/build/assets/main-Nouveau.js', revision: null },
    { url: '/build/assets/Show-Nouveau.js', revision: null },
    { url: '/build/assets/vue-core-Commun.js', revision: null },
    { url: '/build/assets/archivo-latin-Police.woff2', revision: 'a1' },
    { url: '/build/hors-ligne.html', revision: 'b2' },
    { url: '/logo.svg', revision: 'c2' },
]

const mettreDeCote = (caches, manifeste = manifesteDeLaSuivante) =>
    mettreDeCoteLaConstructionPrecedente({ magasin: caches, nomDuPrecache: PRECACHE, manifeste, origine: ORIGINE })

describe('à l’installation', () => {
    it('met de côté les actifs du precache que le nouveau manifeste n’a plus, et eux seuls', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)

        await mettreDeCote(caches)

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toEqual([
            `${ORIGINE}/build/assets/main-Ancien.js`,
            `${ORIGINE}/build/assets/Show-Ancien.js`,
        ])
        const miseDeCote = await caches.open(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)
        await expect((await miseDeCote.match(`${ORIGINE}/build/assets/Show-Ancien.js`)).text()).resolves.toBe(
            'séance ancienne',
        )
    })

    it('ne retire rien du precache, que Workbox vide à l’activation', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)
        const avant = caches.adresses(PRECACHE)

        await mettreDeCote(caches)

        expect(caches.adresses(PRECACHE)).toEqual(avant)
    })

    /*
     * La page « hors ligne » et les icônes reviennent sous la même adresse,
     * leur révision changée : ce ne sont pas des actifs construits, et la
     * route des actifs ne les sert pas.
     */
    it('ne met de côté ni la page « hors ligne », ni une icône, ni un actif dont seule la révision change', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)

        await mettreDeCote(caches, [
            ...manifesteDeLaSuivante.filter((entree) => !entree.url.includes('archivo')),
            { url: '/build/assets/archivo-latin-Police.woff2', revision: 'a2' },
        ])

        const misDeCote = caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)

        expect(misDeCote).not.toContain(`${ORIGINE}/build/hors-ligne.html`)
        expect(misDeCote).not.toContain(`${ORIGINE}/logo.svg`)
        expect(misDeCote).not.toContain(`${ORIGINE}/build/assets/archivo-latin-Police.woff2`)
    })

    it('met de côté, sous son adresse sans révision, un actif à révision que le manifeste n’a plus', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)

        await mettreDeCote(
            caches,
            manifesteDeLaSuivante.filter((entree) => !entree.url.includes('archivo')),
        )

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toContain(
            `${ORIGINE}/build/assets/archivo-latin-Police.woff2`,
        )
    })

    it('jette d’abord ce qu’une installation précédente avait mis de côté', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)
        const restes = await caches.open(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)
        await restes.put(`${ORIGINE}/build/assets/main-Echoue.js`, actif('installation échouée'))

        await mettreDeCote(caches)

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).not.toContain(
            `${ORIGINE}/build/assets/main-Echoue.js`,
        )
    })

    it('ne fait rien à la première installation, sans precache, et ne le crée pas', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)

        await mettreDeCote(caches)

        expect(caches.adresses(PRECACHE)).toBeNull()
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toBeNull()
    })

    /*
     * Mettre de côté est un confort : un stockage à court de place ne doit
     * pas faire échouer l'installation, qui laisserait la page sur l'ancien
     * worker, sans mise à jour possible (seconde relecture de la partie E).
     */
    it('ne rejette pas quand le stockage refuse ce qu’elle range', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)
        caches.refuserLesEcritures(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)

        await expect(mettreDeCote(caches)).resolves.toBeUndefined()
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toEqual([])
    })

    it('ne met de côté aucun actif d’une autre origine', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        const precache = await caches.open(PRECACHE)
        await precache.put('https://cdn.example.org/build/assets/main-Ailleurs.js', actif('ailleurs'))

        await mettreDeCote(caches)

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toEqual([])
    })
})

describe('à l’activation', () => {
    it('vide la construction précédente et y range ce que l’installation a mis de côté', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        const avantDerniere = await caches.open(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)
        await avantDerniere.put(`${ORIGINE}/build/assets/main-AvantDernier.js`, actif('il y a deux déploiements'))
        await precacheDeLaPremiereConstruction(caches)
        await mettreDeCote(caches)

        await basculerLaConstructionPrecedente(caches)

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)).toEqual([
            `${ORIGINE}/build/assets/main-Ancien.js`,
            `${ORIGINE}/build/assets/Show-Ancien.js`,
        ])
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toBeNull()
        const reponse = await caches.match(`${ORIGINE}/build/assets/main-Ancien.js`, {
            cacheName: CACHE_DE_LA_CONSTRUCTION_PRECEDENTE,
        })
        await expect(reponse.text()).resolves.toBe('entrée ancienne')
    })

    it('ne rejette pas quand le stockage refuse ce qu’elle range, et jette ce qui était mis de côté', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        await precacheDeLaPremiereConstruction(caches)
        await mettreDeCote(caches)
        caches.refuserLesEcritures(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)

        await expect(basculerLaConstructionPrecedente(caches)).resolves.toBeUndefined()
        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE)).toBeNull()
    })

    it('vide la construction précédente même quand rien n’a été mis de côté', async () => {
        const caches = creerDesCachesEnMemoire(`${ORIGINE}/sw.js`)
        const avantDerniere = await caches.open(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)
        await avantDerniere.put(`${ORIGINE}/build/assets/main-AvantDernier.js`, actif('il y a deux déploiements'))

        await basculerLaConstructionPrecedente(caches)

        expect(caches.adresses(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE)).toBeNull()
    })
})

describe('les noms des caches', () => {
    /* `cleanupOutdatedCaches` efface tout cache dont le nom porte `-precache-`. */
    it('échappent au nettoyage des precaches de Workbox, et à la coupe des actifs à la demande', () => {
        for (const nom of [CACHE_DE_LA_CONSTRUCTION_PRECEDENTE, CACHE_DE_LA_CONSTRUCTION_MISE_DE_COTE]) {
            expect(nom).not.toContain('-precache-')
            expect(nom).not.toBe(NOM_DU_CACHE)
        }

        expect(CACHE_DE_LA_CONSTRUCTION_PRECEDENTE).toBe('construction-precedente')
    })
})
