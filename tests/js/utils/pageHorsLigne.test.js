import { describe, it, expect, vi } from 'vitest'
import { FICHIER_DE_LA_PAGE_HORS_LIGNE, greffonDeLaPageHorsLigne, pageHorsLigne } from '@/sw/pageHorsLigne'

/** La page lue comme un document, pour la questionner par sélecteurs. */
const document_ = (html) => new DOMParser().parseFromString(html, 'text/html')

describe('la page « hors ligne »', () => {
    const html = pageHorsLigne({ feuilles: ['/build/assets/main-abc123.css'] })
    const page = document_(html)

    it('dit que le réseau manque, en français', () => {
        expect(page.documentElement.lang).toBe('fr')
        expect(page.title).toBe('Hors ligne - GymTracker')
        expect(page.querySelector('h1').textContent).toBe('Pas de réseau')
    })

    it('prend la feuille de style de l’application, et rien d’autre de l’extérieur', () => {
        expect([...page.querySelectorAll('link[rel="stylesheet"]')].map((lien) => lien.getAttribute('href'))).toEqual([
            '/build/assets/main-abc123.css',
        ])
        expect(page.querySelectorAll('script[src], img, iframe')).toHaveLength(0)
    })

    it('ne porte rien d’un compte : ni données de page, ni jeton, ni identité', () => {
        // Une page servie à quiconque ouvre l'application sans réseau, quel que
        // soit le compte qui s'y est connecté avant : elle doit être fixe.
        expect(html).not.toMatch(/data-page|csrf|auth|user|@/i)
    })

    it('propose de redemander la page qui n’a pas pu s’ouvrir', () => {
        const reessayer = page.querySelector('a')

        expect(reessayer.textContent).toBe('Réessayer')
        expect(reessayer.getAttribute('href')).toBe('')
    })

    it('se recharge d’elle-même au retour du réseau', () => {
        expect(page.querySelector('script:not([src])').textContent).toContain("addEventListener('online'")
    })

    it('échappe une adresse de feuille, même improbable', () => {
        const piegee = pageHorsLigne({ feuilles: ['/build/assets/a"><script>x</script>.css'] })

        expect(piegee).not.toContain('<script>x</script>')
        expect(document_(piegee).querySelector('link').getAttribute('href')).toBe(
            '/build/assets/a"><script>x</script>.css',
        )
    })
})

describe('le greffon de construction', () => {
    /**
     * Joue `generateBundle` sur un paquet de construction, et rend ce qu'il émet.
     *
     * @param {Record<string, object>} paquet
     */
    const construire = (paquet) => {
        const emis = []
        const erreurs = []
        const contexte = {
            emitFile: (fichier) => emis.push(fichier),
            error: vi.fn((message) => {
                erreurs.push(message)
                throw new Error(message)
            }),
        }

        try {
            greffonDeLaPageHorsLigne().generateBundle.call(contexte, {}, paquet)
        } catch {
            // L'erreur est relevée dans `erreurs`.
        }

        return { emis, erreurs }
    }

    it('écrit la page dans public/build, avec la feuille de style de l’entrée construite', () => {
        const { emis } = construire({
            'assets/main-Ab12.js': {
                type: 'chunk',
                isEntry: true,
                viteMetadata: { importedCss: new Set(['assets/main-Cd34.css']) },
            },
            'assets/Stats-Ef56.js': {
                type: 'chunk',
                isEntry: false,
                viteMetadata: { importedCss: new Set(['assets/Stats-Gh78.css']) },
            },
            'assets/main-Cd34.css': { type: 'asset' },
        })

        expect(emis).toHaveLength(1)
        expect(emis[0].type).toBe('asset')
        expect(emis[0].fileName).toBe(FICHIER_DE_LA_PAGE_HORS_LIGNE)
        expect(emis[0].source).toContain('<link rel="stylesheet" href="/build/assets/main-Cd34.css">')
        expect(emis[0].source).not.toContain('Stats-Gh78.css')
    })

    it('arrête la construction plutôt que d’écrire une page sans style', () => {
        const { emis, erreurs } = construire({ 'assets/main-Ab12.js': { type: 'chunk', isEntry: true } })

        expect(emis).toHaveLength(0)
        expect(erreurs).toHaveLength(1)
    })

    it('ne tourne qu’à la construction', () => {
        expect(greffonDeLaPageHorsLigne().apply).toBe('build')
    })

    it('écrit le fichier que le worker sert', () => {
        expect(FICHIER_DE_LA_PAGE_HORS_LIGNE).toBe('hors-ligne.html')
    })
})
