import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

import {
    ADRESSE_DE_LA_CONSTRUCTION,
    DOSSIER_DES_PAGES,
    ENTREE_DE_L_APPLICATION,
    GRAPHIQUES_INDISPONIBLES_HORS_LIGNE,
    PAGES_PRECACHEES_SANS_COPIE,
    fermetureDuPrecache,
    greffonDeLaFermetureDuPrecache,
    pointsDeDepartDuPrecache,
} from '@/sw/fermetureDuPrecache'
import { PAGES_GARDEES } from '@/Utils/pagesGardees'
import { filesMatching, jsRoot } from '../conventions/sourceFiles'

const racineDuDepot = resolve(jsRoot, '../..')

/*
 * La fermeture du precache (#2048, partie E) : ce que l'entrée et les pages
 * de la salle demandent, lu dans le manifeste de la construction, sans les
 * pages qu'elles ne sont pas ni les graphiques nommés. `ServiceWorkerAssetsTest`
 * la refait en PHP sur la vraie construction ; ici, le calcul sur des
 * manifestes faits à la main, et le greffon qui la verse au precache.
 */

const ACCUEIL = `${DOSSIER_DES_PAGES}Dashboard.vue`
const SEANCE = `${DOSSIER_DES_PAGES}Workouts/Show.vue`
const STATS = `${DOSSIER_DES_PAGES}Stats/Index.vue`
const GRAPHIQUE = 'resources/js/Components/Stats/WeeklyVolumeChart.vue'
const AUTRE_GRAPHIQUE = 'resources/js/Components/Stats/MonthlyVolumeChart.vue'

/**
 * Un manifeste de construction, comme Vite l'écrit : l'entrée charge chaque
 * page à la demande, l'accueil un graphique, qui seul importe Chart.js, et la
 * séance le glisser-déposer.
 */
const manifesteFactice = () => ({
    [ENTREE_DE_L_APPLICATION]: {
        file: 'assets/main-A1.js',
        isEntry: true,
        css: ['assets/main-A2.css'],
        assets: ['assets/archivo-latin-A3.woff2', 'assets/logo-A4.png'],
        imports: ['_vue-core-B1.js'],
        dynamicImports: [ACCUEIL, SEANCE, STATS, '_SyncService-B2.js'],
    },
    '_vue-core-B1.js': { file: 'assets/vue-core-B1.js' },
    '_SyncService-B2.js': { file: 'assets/SyncService-B2.js', imports: ['_vue-core-B1.js'] },
    [ACCUEIL]: {
        file: 'assets/Dashboard-C1.js',
        imports: ['_vue-core-B1.js', '_graphiqueAsynchrone-C2.js'],
        dynamicImports: [GRAPHIQUE],
    },
    '_graphiqueAsynchrone-C2.js': { file: 'assets/graphiqueAsynchrone-C2.js' },
    [GRAPHIQUE]: { file: 'assets/WeeklyVolumeChart-D1.js', imports: ['_BaseChart-D2.js', '_vue-core-B1.js'] },
    '_BaseChart-D2.js': { file: 'assets/BaseChart-D2.js', css: ['assets/BaseChart-D3.css'] },
    [SEANCE]: {
        file: 'assets/Show-E1.js',
        css: ['assets/Show-E2.css'],
        imports: ['_vue-core-B1.js'],
        dynamicImports: ['node_modules/@formkit/drag-and-drop/vue/index.mjs'],
    },
    'node_modules/@formkit/drag-and-drop/vue/index.mjs': { file: 'assets/vue-E3.js', imports: ['_vue-core-B1.js'] },
    [STATS]: { file: 'assets/Index-F1.js', dynamicImports: [AUTRE_GRAPHIQUE] },
    [AUTRE_GRAPHIQUE]: { file: 'assets/MonthlyVolumeChart-F2.js', imports: ['_BaseChart-D2.js'] },
})

/** La fermeture du manifeste factice, depuis l'entrée, l'accueil et la séance. */
const fermer = (manifeste = manifesteFactice(), options = {}) =>
    fermetureDuPrecache(manifeste, {
        depart: [ENTREE_DE_L_APPLICATION, ACCUEIL, SEANCE],
        graphiques: [GRAPHIQUE],
        ...options,
    })

describe('la fermeture du precache', () => {
    it('suit les feuilles, les imports et les chargements à la demande de chaque point de départ, triés', () => {
        expect(fermer()).toEqual({
            fichiers: [
                'assets/Dashboard-C1.js',
                'assets/Show-E1.js',
                'assets/Show-E2.css',
                'assets/SyncService-B2.js',
                'assets/graphiqueAsynchrone-C2.js',
                'assets/logo-A4.png',
                'assets/main-A1.js',
                'assets/main-A2.css',
                'assets/vue-E3.js',
                'assets/vue-core-B1.js',
            ],
            graphiquesEcartes: [GRAPHIQUE],
        })
    })

    /*
     * L'entrée charge chaque page à la demande (`import.meta.glob`) : la suivre
     * jusqu'à elles mettrait toute l'application au precache.
     */
    it('ne suit pas une page qui n’est pas un point de départ, ni ce qu’elle seule charge', () => {
        const { fichiers } = fermer()

        expect(fichiers).not.toContain('assets/Index-F1.js')
        expect(fichiers).not.toContain('assets/MonthlyVolumeChart-F2.js')
    })

    it('écarte un graphique nommé, et Chart.js, que seuls les graphiques importent', () => {
        const { fichiers } = fermer()

        expect(fichiers).not.toContain('assets/WeeklyVolumeChart-D1.js')
        expect(fichiers).not.toContain('assets/BaseChart-D2.js')
        expect(fichiers).not.toContain('assets/BaseChart-D3.css')
    })

    it('garde ce qu’un graphique écarté importe quand une autre voie l’atteint', () => {
        // vue-core est importé par le graphique ET par l'entrée.
        expect(fermer().fichiers).toContain('assets/vue-core-B1.js')
    })

    it('laisse les polices aux motifs de vite.config.js, et prend les autres actifs', () => {
        const { fichiers } = fermer()

        expect(fichiers).not.toContain('assets/archivo-latin-A3.woff2')
        expect(fichiers).toContain('assets/logo-A4.png')
    })

    it('arrête la construction sur un graphique qu’une page de départ charge sans qu’il soit nommé', () => {
        expect(() => fermer(manifesteFactice(), { graphiques: [] })).toThrow(
            `${ACCUEIL} charge le graphique ${GRAPHIQUE}, absent de GRAPHIQUES_INDISPONIBLES_HORS_LIGNE`,
        )
    })

    it('arrête la construction sur un graphique nommé qu’aucune page de départ ne charge plus', () => {
        expect(() => fermer(manifesteFactice(), { graphiques: [GRAPHIQUE, AUTRE_GRAPHIQUE] })).toThrow(
            `aucune page précachée ne charge plus : ${AUTRE_GRAPHIQUE}`,
        )
    })

    it('arrête la construction sur un graphique importé sans graphiqueAsynchrone', () => {
        const manifeste = manifesteFactice()
        manifeste[SEANCE].imports.push(AUTRE_GRAPHIQUE)

        expect(() => fermer(manifeste)).toThrow(
            `${SEANCE} importe le graphique ${AUTRE_GRAPHIQUE} sans graphiqueAsynchrone`,
        )
    })

    it('arrête la construction sur un point de départ ou un import absent du manifeste', () => {
        expect(() =>
            fermer(manifesteFactice(), { depart: [ENTREE_DE_L_APPLICATION, 'resources/js/Pages/HorsLigne.vue'] }),
        ).toThrow('Le point de départ du precache resources/js/Pages/HorsLigne.vue est absent du manifeste')

        const manifeste = manifesteFactice()
        manifeste[SEANCE].imports.push('_Disparu-Z9.js')

        expect(() => fermer(manifeste)).toThrow(`${SEANCE} importe _Disparu-Z9.js, absent du manifeste`)
    })

    it('ne lit pas une clé héritée comme une entrée du manifeste', () => {
        expect(() => fermer(manifesteFactice(), { depart: [ENTREE_DE_L_APPLICATION, 'toString'] })).toThrow(
            'Le point de départ du precache toString est absent du manifeste',
        )
    })
})

describe('les points de départ', () => {
    it('sont l’entrée, puis chaque page gardée et chaque page précachée sans copie, une fois chacune', () => {
        const composants = [
            ...new Set([...PAGES_GARDEES.map((page) => page.composant), ...PAGES_PRECACHEES_SANS_COPIE]),
        ]

        expect(pointsDeDepartDuPrecache()).toEqual([
            ENTREE_DE_L_APPLICATION,
            ...composants.map((composant) => `${DOSSIER_DES_PAGES}${composant}.vue`),
        ])
        expect(pointsDeDepartDuPrecache()).toContain(SEANCE)
    })

    /* La page `HorsLigne` de la partie F s'ajoutera aux pages précachées sans copie. */
    it('prennent une page précachée sans copie, et ne nomment pas deux fois une page aussi gardée', () => {
        expect(
            pointsDeDepartDuPrecache({
                pagesGardees: [{ composant: 'Dashboard' }, { composant: 'Workouts/Show' }],
                pagesSansCopie: ['HorsLigne', 'Dashboard'],
            }),
        ).toEqual([ENTREE_DE_L_APPLICATION, ACCUEIL, SEANCE, `${DOSSIER_DES_PAGES}HorsLigne.vue`])
    })

    it('partent de l’entrée que vite.config.js construit, et de pages qui existent', () => {
        const configuration = readFileSync(join(racineDuDepot, 'vite.config.js'), 'utf8')

        expect(configuration).toContain(`input: '${ENTREE_DE_L_APPLICATION}'`)

        for (const point of pointsDeDepartDuPrecache().slice(1)) {
            expect(() => readFileSync(join(racineDuDepot, point))).not.toThrow()
        }
    })
})

describe('les graphiques indisponibles hors ligne', () => {
    const source = readFileSync(join(racineDuDepot, 'resources/js/sw/fermetureDuPrecache.js'), 'utf8')

    /**
     * Le corps d'une liste gelée du module, ligne à ligne.
     *
     * @param {string} nom
     */
    const lignesDe = (nom) => {
        const debut = source.indexOf(`export const ${nom} = Object.freeze([`)
        const fin = source.indexOf('])', debut)

        return source
            .slice(debut, fin)
            .split('\n')
            .slice(1)
            .filter((ligne) => ligne.trim() !== '')
    }

    it('s’écrivent une entrée par ligne, la forme que Tests\\Support\\FermetureDuPrecache lit', () => {
        expect(lignesDe('GRAPHIQUES_INDISPONIBLES_HORS_LIGNE')).toHaveLength(GRAPHIQUES_INDISPONIBLES_HORS_LIGNE.length)

        for (const ligne of lignesDe('GRAPHIQUES_INDISPONIBLES_HORS_LIGNE')) {
            expect(ligne).toMatch(/^\s*'resources\/js\/Components\/Stats\/\w+Chart\.vue',?\s*$/)
        }

        expect(source).toContain('export const PAGES_PRECACHEES_SANS_COPIE = Object.freeze([')
    })

    it('ne nomment chacun qu’une fois, triés, et chacun se charge par graphiqueAsynchrone', () => {
        expect(GRAPHIQUES_INDISPONIBLES_HORS_LIGNE).toEqual([...new Set(GRAPHIQUES_INDISPONIBLES_HORS_LIGNE)].sort())

        const charges = new Set(
            filesMatching(/graphiqueAsynchrone\(/).flatMap((fichier) =>
                [
                    ...readFileSync(join(racineDuDepot, fichier), 'utf8').matchAll(
                        /graphiqueAsynchrone\(\s*\(\) => import\('@\/(Components\/Stats\/\w+Chart\.vue)'\)/g,
                    ),
                ].map(([, chemin]) => `resources/js/${chemin}`),
            ),
        )

        for (const graphique of GRAPHIQUES_INDISPONIBLES_HORS_LIGNE) {
            expect(charges, graphique).toContain(graphique)
        }
    })

    it('ne se gèlent pas moins que la liste des pages', () => {
        expect(Object.isFrozen(GRAPHIQUES_INDISPONIBLES_HORS_LIGNE)).toBe(true)
        expect(Object.isFrozen(PAGES_PRECACHEES_SANS_COPIE)).toBe(true)
    })

    it('vivent dans un module que vite.config.js lit sans Vue ni Inertia', () => {
        const imports = [...source.matchAll(/^import .* from '([^']+)'$/gm)].map(([, chemin]) => chemin)

        expect(imports).toEqual(['../Utils/pagesGardees.js'])
        expect(source).not.toMatch(/\bimport\s*\(/)
    })
})

describe('le greffon de construction', () => {
    /** Un vite-plugin-pwa factice : ses entrées supplémentaires, que le greffon étend. */
    const pwaFactice = (entrees = [{ url: '/logo.svg', revision: 'empreinte' }]) => {
        const api = {
            entrees,
            extendManifestEntries: vi.fn((rappel) => {
                api.entrees = rappel(api.entrees)
            }),
        }

        return api
    }

    /**
     * Joue `configResolved` puis `writeBundle` comme Vite, et rend ce que le
     * greffon a dit.
     *
     * @param {{ pwa?: object | null, manifeste?: string | false | true, paquet?: object }} options
     */
    const construire = ({ pwa = pwaFactice(), manifeste = 'manifest.json', paquet } = {}) => {
        const erreurs = []
        const greffon = greffonDeLaFermetureDuPrecache()
        const contexte = {
            error: (message) => {
                erreurs.push(message)
                throw new Error(message)
            },
        }

        greffon.configResolved({
            plugins: [
                { name: 'vite-plugin-pwa:build' },
                ...(pwa === null ? [] : [{ name: 'vite-plugin-pwa', api: pwa }]),
            ],
            build: { manifest: manifeste },
        })

        try {
            greffon.writeBundle.call(contexte, {}, paquet ?? { 'manifest.json': { type: 'asset', source: '{}' } })
        } catch {
            // L'erreur est relevée dans `erreurs`.
        }

        return { erreurs, pwa }
    }

    /** Le vrai manifeste factice, réduit aux points de départ que la vraie liste nomme. */
    const paquetDe = (manifeste, fichier = 'manifest.json', encoder = false) => {
        const texte = JSON.stringify(manifeste)

        return { [fichier]: { type: 'asset', source: encoder ? new TextEncoder().encode(texte) : texte } }
    }

    /** Un manifeste où chaque point de départ existe, la séance chargeant le glisser-déposer. */
    const manifesteDesVraisPoints = () => {
        const manifeste = Object.fromEntries(
            pointsDeDepartDuPrecache().map((point, rang) => [point, { file: `assets/point-${rang}.js` }]),
        )

        manifeste[SEANCE].dynamicImports = ['node_modules/@formkit/drag-and-drop/vue/index.mjs']
        manifeste['node_modules/@formkit/drag-and-drop/vue/index.mjs'] = { file: 'assets/vue-E3.js' }

        for (const [rang, graphique] of GRAPHIQUES_INDISPONIBLES_HORS_LIGNE.entries()) {
            manifeste[ACCUEIL].dynamicImports = [...(manifeste[ACCUEIL].dynamicImports ?? []), graphique]
            manifeste[graphique] = { file: `assets/graphique-${rang}.js` }
        }

        return manifeste
    }

    it('verse la fermeture aux entrées supplémentaires du precache, à son adresse et sans révision', () => {
        const { erreurs, pwa } = construire({ paquet: paquetDe(manifesteDesVraisPoints()) })

        expect(erreurs).toEqual([])
        expect(pwa.extendManifestEntries).toHaveBeenCalledOnce()
        expect(pwa.entrees[0]).toEqual({ url: '/logo.svg', revision: 'empreinte' })
        expect(pwa.entrees.slice(1)).toEqual(
            fermetureDuPrecache(manifesteDesVraisPoints()).fichiers.map((fichier) => ({
                url: `${ADRESSE_DE_LA_CONSTRUCTION}${fichier}`,
                revision: null,
            })),
        )
        expect(pwa.entrees.map((entree) => entree.url)).toContain('/build/assets/vue-E3.js')
        expect(pwa.entrees.map((entree) => entree.url)).not.toContain('/build/assets/graphique-0.js')
    })

    it('ne double pas une entrée supplémentaire déjà là', () => {
        const { pwa } = construire({
            pwa: pwaFactice([{ url: '/build/assets/vue-E3.js', revision: null }]),
            paquet: paquetDe(manifesteDesVraisPoints()),
        })
        const urls = pwa.entrees.map((entree) => entree.url)

        expect(urls).toEqual([...new Set(urls)])
        expect(urls[0]).toBe('/build/assets/vue-E3.js')
    })

    it('lit le manifeste sous le nom que la configuration lui donne, texte ou octets', () => {
        const { erreurs, pwa } = construire({
            manifeste: true,
            paquet: paquetDe(manifesteDesVraisPoints(), '.vite/manifest.json', true),
        })

        expect(erreurs).toEqual([])
        expect(pwa.entrees.length).toBeGreaterThan(1)
    })

    it('arrête la construction sans vite-plugin-pwa, sans manifeste, ou sur une fermeture refusée', () => {
        expect(construire({ pwa: null }).erreurs).toEqual([
            "La fermeture du precache n'a pas trouvé vite-plugin-pwa dans la construction.",
        ])
        expect(construire({ manifeste: false }).erreurs).toEqual([
            "La fermeture du precache n'a pas trouvé le manifeste de la construction.",
        ])
        expect(construire({ paquet: {} }).erreurs).toEqual([
            "La fermeture du precache n'a pas trouvé le manifeste de la construction.",
        ])

        const sansLeGraphique = manifesteDesVraisPoints()
        sansLeGraphique[ACCUEIL].dynamicImports.push('resources/js/Components/Stats/NouveauChart.vue')
        sansLeGraphique['resources/js/Components/Stats/NouveauChart.vue'] = { file: 'assets/NouveauChart-Z1.js' }
        const { erreurs, pwa } = construire({ paquet: paquetDe(sansLeGraphique) })

        expect(erreurs).toHaveLength(1)
        expect(erreurs[0]).toContain('NouveauChart.vue, absent de GRAPHIQUES_INDISPONIBLES_HORS_LIGNE')
        expect(pwa.extendManifestEntries).not.toHaveBeenCalled()
    })

    it('ne tourne qu’à la construction', () => {
        expect(greffonDeLaFermetureDuPrecache().apply).toBe('build')
    })

    /*
     * Les motifs ne prennent plus que la page « hors ligne » et les polices :
     * un motif de morceau par nom (`assets/Show-*.js`) reprendrait par hasard
     * d'autres pages, et doublerait une entrée de la fermeture, que Workbox
     * refuse à l'installation.
     */
    it('est branché par vite.config.js, dont les motifs ne prennent plus de code', () => {
        const configuration = readFileSync(join(racineDuDepot, 'vite.config.js'), 'utf8')
        const motifs = configuration.slice(
            configuration.indexOf('globPatterns: ['),
            configuration.indexOf('globIgnores'),
        )

        expect(configuration.indexOf('greffonDeLaFermetureDuPrecache()')).toBeGreaterThan(-1)
        expect(configuration.indexOf('greffonDeLaFermetureDuPrecache()')).toBeLessThan(
            configuration.indexOf('VitePWA({'),
        )
        expect(motifs).not.toMatch(/\.(?:\{[^}]*)?(?:js|css)\b/)
        expect(motifs).toContain('FICHIER_DE_LA_PAGE_HORS_LIGNE')
    })
})
