import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

import { jsRoot } from '../conventions/sourceFiles'
import { RAISONS_DE_LA_COQUILLE, envelopperLaCoquille } from '@/sw/coquilleServie'
import { ATTRIBUT_DU_MOTIF, ATTRIBUT_D_ATTENTE, MOTIF_DU_DELAI, pageHorsLigne } from '@/sw/pageHorsLigne'
import { demarrerHorsLigne, estLaCoquille, laisserLaCoquille, raisonDeLaCoquille } from '@/Utils/demarrageHorsLigne'

/*
 * Le démarrage dans la coquille hors ligne (#2048, partie F) : la page que le
 * worker sert, telle qu'il la sert (`envelopperLaCoquille`, raison comprise),
 * lue comme un document à part, sur une fenêtre à elle.
 */

/** Le paragraphe qui dit pourquoi la page n'a pas pu s'ouvrir, tel que la coquille l'écrit. */
const MOTIF_SANS_RESEAU = "Cette page n'a pas pu s'ouvrir : l'appareil n'est pas connecté."

/**
 * La coquille que le worker sert pour `raison`, sur une fenêtre à elle.
 *
 * @param {string} raison
 */
const fenetreDeLaCoquille = async (raison) => {
    const precachee = new Response(
        pageHorsLigne({ feuilles: ['/build/assets/main-Cd34.css'], moduleDeLEntree: '/build/assets/main-Ab12.js' }),
    )
    const servie = await envelopperLaCoquille(precachee, raison)
    const document = new DOMParser().parseFromString(await servie.text(), 'text/html')

    return Object.assign(new EventTarget(), { document })
}

describe('la coquille', () => {
    it('se reconnaît à sa marque, et pas un document du serveur', async () => {
        const fenetre = await fenetreDeLaCoquille('reseau')
        const duServeur = new DOMParser().parseFromString('<html lang="fr"><body></body></html>', 'text/html')

        expect(estLaCoquille(fenetre.document)).toBe(true)
        expect(estLaCoquille(duServeur)).toBe(false)
        expect(estLaCoquille(undefined)).toBe(false)
    })

    it('lit la raison que le worker a posée, et connaît chacune des siennes', async () => {
        expect(raisonDeLaCoquille((await fenetreDeLaCoquille('delai')).document)).toBe('delai')
        expect(raisonDeLaCoquille((await fenetreDeLaCoquille('reseau')).document)).toBe('reseau')
        expect(raisonDeLaCoquille((await fenetreDeLaCoquille('autre')).document)).toBe('reseau')
        expect(RAISONS_DE_LA_COQUILLE).toEqual(['delai', 'reseau'])
    })
})

describe('le contenu statique', () => {
    /*
     * Ce que portait le script en ligne de la page, que la politique du worker
     * (`script-src 'self'`) bloque désormais.
     */
    it('se recharge au retour du réseau, une fois', async () => {
        const fenetre = await fenetreDeLaCoquille('reseau')
        const recharger = vi.fn()

        laisserLaCoquille({ fenetre, recharger })

        expect(recharger).not.toHaveBeenCalled()

        fenetre.dispatchEvent(new Event('online'))
        fenetre.dispatchEvent(new Event('online'))

        expect(recharger).toHaveBeenCalledTimes(1)
    })

    it('sans réseau, garde « Attendre la réponse » caché et son motif', async () => {
        const fenetre = await fenetreDeLaCoquille('reseau')

        laisserLaCoquille({ fenetre, recharger: () => {} })

        expect(fenetre.document.querySelector(`[${ATTRIBUT_D_ATTENTE}]`).hidden).toBe(true)
        expect(fenetre.document.querySelector(`[${ATTRIBUT_DU_MOTIF}]`).textContent).toBe(MOTIF_SANS_RESEAU)
    })

    /*
     * Servie au délai, la page d'un serveur lent mais vivant : attendre sa
     * réponse demande au worker de lever le délai de l'adresse, puis recharge
     * (`attendreLaReponse`, `messagesDuWorker.js`).
     */
    it('au délai, dit que le réseau ne répond pas et propose d’attendre la réponse', async () => {
        const fenetre = await fenetreDeLaCoquille('delai')
        const attendre = vi.fn(() => Promise.resolve())

        laisserLaCoquille({ fenetre, attendre, recharger: () => {} })

        const bouton = fenetre.document.querySelector(`[${ATTRIBUT_D_ATTENTE}]`)

        expect(fenetre.document.querySelector(`[${ATTRIBUT_DU_MOTIF}]`).textContent).toBe(MOTIF_DU_DELAI)
        expect(bouton.hidden).toBe(false)
        expect(attendre).not.toHaveBeenCalled()

        bouton.click()

        expect(attendre).toHaveBeenCalledTimes(1)
        expect(bouton.disabled).toBe(true)
    })

    it('reste tel quel quand rien n’est à monter', async () => {
        const fenetre = await fenetreDeLaCoquille('reseau')
        const avant = fenetre.document.body.innerHTML

        expect(await demarrerHorsLigne({ fenetre, recharger: () => {} })).toEqual({
            montee: false,
            raison: 'sans-copie',
        })
        expect(fenetre.document.body.innerHTML).toBe(avant)
        expect(fenetre.document.querySelector('script[data-page]')).toBeNull()
    })
})

describe('le branchement', () => {
    const main = () => readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')

    /*
     * Un import à la demande ne se chargerait qu'en mode coquille, sans réseau :
     * il ne serait jamais au precache. L'import est statique, et main.js attend
     * le démarrage avant tout ce qu'il installe, sauf l'écoute des messages du
     * worker, que le navigateur délivre à la fin de l'analyse du document.
     */
    it('est importé statiquement par main.js, et attendu avant tout le reste dans la coquille', () => {
        expect(main()).toContain("import { demarrerHorsLigne, estLaCoquille } from '@/Utils/demarrageHorsLigne'")
        expect(main()).not.toMatch(/import\(\s*['"]@\/Utils\/demarrageHorsLigne/)

        const attente = main().indexOf('demarrerHorsLigne().then(')
        const definition = main().indexOf('const demarrerLApplication = () => {')

        expect(attente).toBeGreaterThan(-1)
        expect(main().indexOf('installerLesMessagesDuWorker()')).toBeLessThan(definition)
        expect(definition).toBeLessThan(main().indexOf('inscrireLeWorker('))
        expect(main().indexOf('createInertiaApp({')).toBeLessThan(attente)
        expect(main().slice(attente)).toMatch(/if \(montee\) \{\s*demarrerLApplication\(\)/)
        expect(main()).toMatch(
            /if \(estLaCoquille\(\)\) \{\s*demarrerHorsLigne\(\)\.then\([\s\S]*?\} else \{\s*demarrerLApplication\(\)\s*\}\s*$/,
        )
    })
})
