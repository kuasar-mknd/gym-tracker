import { describe, it, expect, vi } from 'vitest'
import {
    ATTRIBUT_DE_LA_COQUILLE,
    ATTRIBUT_D_ATTENTE,
    AVANT_LA_SALLE,
    FICHIER_DE_LA_PAGE_HORS_LIGNE,
    greffonDeLaPageHorsLigne,
    pageHorsLigne,
} from '@/sw/pageHorsLigne'
import { CADRE_DU_LOGO, TRACES_DE_LA_LETTRE, TRACES_DES_BARRES } from '@/Utils/dessinDuLogo'

/** La page lue comme un document, pour la questionner par sélecteurs. */
const document_ = (html) => new DOMParser().parseFromString(html, 'text/html')

describe('la page « hors ligne »', () => {
    const html = pageHorsLigne({
        feuilles: ['/build/assets/main-abc123.css'],
        moduleDeLEntree: '/build/assets/main-Ab12Cd.js',
    })
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
        expect(page.querySelectorAll('img, iframe')).toHaveLength(0)
    })

    /*
     * La coquille de l'application (#2048, partie F) : la marque que le module
     * de l'entrée lit, et ce module, sous son nom construit. C'est lui qui
     * démarre l'application sur une copie, ou laisse ce contenu.
     */
    it('est la coquille : la marque sur <html>, et le module de l’entrée, seul script', () => {
        expect(page.documentElement.hasAttribute(ATTRIBUT_DE_LA_COQUILLE)).toBe(true)
        expect(ATTRIBUT_DE_LA_COQUILLE).toBe('data-coquille')

        const scripts = [...page.querySelectorAll('script')]

        expect(scripts).toHaveLength(1)
        expect(scripts[0].getAttribute('type')).toBe('module')
        expect(scripts[0].getAttribute('src')).toBe('/build/assets/main-Ab12Cd.js')
        expect(scripts[0].closest('head')).not.toBeNull()
    })

    /*
     * Un démarrage à froid sans réseau ouvre cette page : l'icône `cloud_off`
     * s'y affiche, la police Material Symbols étant au precache (#2048).
     */
    it('montre l’icône cloud_off, muette pour un lecteur d’écran', () => {
        const icone = page.querySelector('.material-symbols-outlined')

        expect(icone.textContent).toBe('cloud_off')
        expect(icone.getAttribute('aria-hidden')).toBe('true')
    })

    /**
     * Le logo de l'application sur sa tuile, tracé dans la page comme dans les
     * pages invitées : il s'affiche sans aucune requête.
     */
    it('montre le logo de l’application seul, nom lu mais caché, sans le charger', () => {
        const logo = page.querySelector('main > div > svg')

        expect(logo).not.toBeNull()
        expect(logo.getAttribute('viewBox')).toBe(CADRE_DU_LOGO)
        expect(logo.getAttribute('aria-hidden')).toBe('true')
        expect(logo.classList).toContain('bg-logo-fond')
        expect(logo.classList).toContain('size-20')
        expect(logo.querySelectorAll('g.fill-logo-lettre path')).toHaveLength(TRACES_DE_LA_LETTRE.length)
        expect(logo.querySelectorAll('g.fill-logo-barres path')).toHaveLength(TRACES_DES_BARRES.length)
        expect(logo.nextElementSibling.textContent).toBe('GymTracker')
        expect(logo.nextElementSibling.classList).toContain('sr-only')
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

    it('dit ce qu’il faut faire avant la salle pour l’ouvrir sans réseau', () => {
        expect(AVANT_LA_SALLE).toBe(
            "Ouvre l'application une fois avec du réseau avant la salle : l'accueil, la séance en cours, les séances, les exercices et les outils resteront disponibles pendant la durée de ta session.",
        )
        expect([...page.querySelectorAll('section p')].map((paragraphe) => paragraphe.textContent)).toContain(
            AVANT_LA_SALLE,
        )
    })

    /*
     * Le worker pose `script-src 'self'` sur la page qu'il sert : un script en
     * ligne y serait bloqué. Le rechargement au retour du réseau, qu'il
     * portait, et « Attendre la réponse » passent par le module de l'entrée
     * (`Utils/demarrageHorsLigne.js`) ; le bouton reste caché sans lui.
     */
    it('n’a aucun script en ligne, et « Attendre la réponse » reste caché tant que le module ne le montre pas', () => {
        expect(page.querySelectorAll('script:not([src])')).toHaveLength(0)
        expect(html).not.toMatch(/\son[a-z]+=/i)

        const attendre = page.querySelector(`[${ATTRIBUT_D_ATTENTE}]`)

        expect(attendre.tagName).toBe('BUTTON')
        expect(attendre.getAttribute('type')).toBe('button')
        expect(attendre.hidden).toBe(true)
        expect(attendre.textContent).toBe('Attendre la réponse')
    })

    it('échappe une adresse de feuille ou de module, même improbable', () => {
        const piegee = pageHorsLigne({
            feuilles: ['/build/assets/a"><script>x</script>.css'],
            moduleDeLEntree: '/build/assets/b"><script>y</script>.js',
        })

        expect(piegee).not.toContain('<script>x</script>')
        expect(piegee).not.toContain('<script>y</script>')
        expect(document_(piegee).querySelector('link').getAttribute('href')).toBe(
            '/build/assets/a"><script>x</script>.css',
        )
        expect(document_(piegee).querySelector('script').getAttribute('src')).toBe(
            '/build/assets/b"><script>y</script>.js',
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

    it('écrit la page dans public/build, avec la feuille de style et le module de l’entrée construite', () => {
        const { emis } = construire({
            'assets/main-Ab12.js': {
                type: 'chunk',
                isEntry: true,
                fileName: 'assets/main-Ab12.js',
                viteMetadata: { importedCss: new Set(['assets/main-Cd34.css']) },
            },
            'assets/Stats-Ef56.js': {
                type: 'chunk',
                isEntry: false,
                fileName: 'assets/Stats-Ef56.js',
                viteMetadata: { importedCss: new Set(['assets/Stats-Gh78.css']) },
            },
            'assets/main-Cd34.css': { type: 'asset' },
        })

        expect(emis).toHaveLength(1)
        expect(emis[0].type).toBe('asset')
        expect(emis[0].fileName).toBe(FICHIER_DE_LA_PAGE_HORS_LIGNE)
        expect(emis[0].source).toContain('<link rel="stylesheet" href="/build/assets/main-Cd34.css">')
        expect(emis[0].source).toContain('<script type="module" src="/build/assets/main-Ab12.js"></script>')
        expect(emis[0].source).not.toContain('Stats-Gh78.css')
        expect(emis[0].source).not.toContain('Stats-Ef56.js')
    })

    it('arrête la construction plutôt que d’écrire une page sans style', () => {
        const { emis, erreurs } = construire({
            'assets/main-Ab12.js': { type: 'chunk', isEntry: true, fileName: 'assets/main-Ab12.js' },
        })

        expect(emis).toHaveLength(0)
        expect(erreurs).toHaveLength(1)
    })

    it('arrête la construction sans entrée, ou avec plusieurs : la coquille ne charge qu’un module', () => {
        const entree = (nom) => ({
            type: 'chunk',
            isEntry: true,
            fileName: nom,
            viteMetadata: { importedCss: new Set(['assets/main-Cd34.css']) },
        })

        for (const paquet of [
            { 'assets/main-Cd34.css': { type: 'asset' } },
            {
                'assets/main-Ab12.js': entree('assets/main-Ab12.js'),
                'assets/autre-Zz.js': entree('assets/autre-Zz.js'),
            },
        ]) {
            const { emis, erreurs } = construire(paquet)

            expect(emis).toHaveLength(0)
            expect(erreurs).toHaveLength(1)
        }
    })

    it('ne tourne qu’à la construction', () => {
        expect(greffonDeLaPageHorsLigne().apply).toBe('build')
    })

    it('écrit le fichier que le worker sert', () => {
        expect(FICHIER_DE_LA_PAGE_HORS_LIGNE).toBe('hors-ligne.html')
    })
})
