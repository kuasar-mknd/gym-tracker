import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { EN_TETES_DE_LA_COQUILLE, RAISONS_DE_LA_COQUILLE, envelopperLaCoquille } from '@/sw/coquilleServie'
import { pageHorsLigne } from '@/sw/pageHorsLigne'
import { jsRoot } from '../conventions/sourceFiles'

/*
 * La page « hors ligne » telle que le worker la sert (#2048, partie F) : la
 * page du precache, marquée de sa raison et enveloppée des en-têtes de
 * sécurité de l'application, et de rien d'autre. Que ces en-têtes soient
 * exactement ceux du serveur, `ServiceWorkerAssetsTest` le vérifie depuis
 * `SecurityHeaders` et `CustomPolicy`.
 */

const PAGE = pageHorsLigne({ feuilles: ['/build/assets/main-Un.css'], moduleDeLEntree: '/build/assets/main-Un.js' })

/** La page telle que le precache la rend : les en-têtes du fichier statique, pas ceux de l'application. */
const precachee = () =>
    new Response(PAGE, { headers: { 'Content-Type': 'text/html', ETag: '"statique"', 'Set-Cookie': 'a=b' } })

describe('envelopperLaCoquille', () => {
    it.each(RAISONS_DE_LA_COQUILLE)('marque la page de la raison « %s », sur <html>', async (raison) => {
        const servie = await envelopperLaCoquille(precachee(), raison)
        const document = new DOMParser().parseFromString(await servie.text(), 'text/html')

        expect(document.documentElement.dataset.raison).toBe(raison)
        expect(document.documentElement.lang).toBe('fr')
        expect(document.documentElement.hasAttribute('data-coquille')).toBe(true)
        expect(document.querySelector('[dusk="page-hors-ligne"]')).not.toBeNull()
    })

    it('ne laisse entrer dans la page qu’une raison de la liste', async () => {
        const servie = await envelopperLaCoquille(precachee(), '"><script>alert(1)</script>')
        const texte = await servie.text()

        expect(texte).toContain('<html data-raison="reseau" lang="fr" data-coquille>')
        expect(texte).not.toContain('alert(1)')
    })

    it('ne change rien d’autre au document', async () => {
        const servie = await envelopperLaCoquille(precachee(), 'delai')

        await expect(servie.text()).resolves.toBe(
            PAGE.replace('<html lang="fr" data-coquille>', '<html data-raison="delai" lang="fr" data-coquille>'),
        )
    })

    it('porte exactement les en-têtes de sécurité, et rien de la réponse du precache', async () => {
        const servie = await envelopperLaCoquille(precachee(), 'reseau')

        expect(servie.status).toBe(200)
        expect([...servie.headers.keys()].sort()).toEqual(
            ['content-type', ...Object.keys(EN_TETES_DE_LA_COQUILLE).map((nom) => nom.toLowerCase())].sort(),
        )
        expect(servie.headers.get('content-type')).toBe('text/html; charset=utf-8')

        for (const [nom, valeur] of Object.entries(EN_TETES_DE_LA_COQUILLE)) {
            expect(servie.headers.get(nom)).toBe(valeur)
        }
    })

    it('n’admet ni nonce ni script en ligne, et refuse d’être encadrée ailleurs', () => {
        const politique = EN_TETES_DE_LA_COQUILLE['Content-Security-Policy']
        const scripts = politique.split(';').find((directive) => directive.startsWith('script-src '))

        expect(politique).not.toMatch(/nonce-|unsafe-eval/)
        expect(scripts).toBe("script-src 'self'")
        expect(politique.split(';')).toContain("frame-ancestors 'self'")
        expect(EN_TETES_DE_LA_COQUILLE['X-Frame-Options']).toBe('SAMEORIGIN')
        expect(Object.isFrozen(EN_TETES_DE_LA_COQUILLE)).toBe(true)
    })

    it('ne rend rien sans page au precache, ni quand elle ne se lit pas', async () => {
        const illisible = new Response(PAGE)
        await illisible.text()

        await expect(envelopperLaCoquille(undefined, 'reseau')).resolves.toBeUndefined()
        await expect(envelopperLaCoquille(illisible, 'reseau')).resolves.toBeUndefined()
    })
})

describe('le module', () => {
    it('ne lit les en-têtes que d’un fichier de pures données, que le test PHP relit', () => {
        const donnees = JSON.parse(readFileSync(resolve(jsRoot, 'sw/enTetesDeLaCoquille.json'), 'utf8'))

        expect(EN_TETES_DE_LA_COQUILLE).toEqual(donnees)
    })
})
