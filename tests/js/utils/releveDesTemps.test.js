import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
    DRAPEAU_DE_L_AFFICHAGE,
    FONCTION_DE_CONSOLE,
    FRAGMENT_QUI_AFFICHE,
    MARQUE_DU_TOUCHER,
    MESURES_AU_PLUS,
    PREFIXE_DES_NAVIGATIONS,
    RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS,
    centile,
    cheminNormalise,
    installerLeReleveDesTemps,
    ligneDuReleve,
    noterLaRequete,
    tableauDuReleve,
    tempsDuServeur,
} from '@/Utils/releveDesTemps'
import { http } from '@/Utils/http'
import { jsRoot } from '../conventions/sourceFiles'

/*
 * Le relevé des temps dans le navigateur (#1315, #2148, étape 1), sur des
 * entrées de `PerformanceResourceTiming` fabriquées : jsdom n'a ni
 * `PerformanceObserver`, ni entrée `navigation`, ni `serverTiming`. Le vrai
 * client d'Inertia est dans `releveDesTempsAvecInertia.test.js`.
 */

const ORIGINE = 'https://gym.example.org'

/** Les métriques de `Server-Timing`, comme `TempsDuServeur` les pose. */
const tempsDuServeurPoses = ({ app = 40, sql = 12, requetes = 7, cache = 2, lectures = 8 } = {}) => [
    { name: 'app', duration: app, description: '' },
    { name: 'routage', duration: 1, description: '' },
    { name: 'controleur', duration: 20, description: '' },
    { name: 'rendu', duration: 19, description: '' },
    { name: 'sql', duration: sql, description: `${requetes} requetes` },
    { name: 'cache', duration: cache, description: `${lectures} lectures` },
]

/** Une entrée de requête, de même origine par défaut. */
const entreeDeRequete = (chemin, options = {}) => ({
    name: chemin.startsWith('http') ? chemin : `${ORIGINE}${chemin}`,
    initiatorType: 'xmlhttprequest',
    requestStart: 100,
    responseStart: 160,
    fetchStart: 99,
    workerStart: 0,
    serverTiming: tempsDuServeurPoses(),
    ...options,
})

/** Un routeur d'Inertia réduit à ses évènements. */
const routeurSimule = () => {
    const ecouteurs = new Map()

    return {
        on(type, rappel) {
            ecouteurs.set(type, [...(ecouteurs.get(type) ?? []), rappel])

            return () =>
                ecouteurs.set(
                    type,
                    (ecouteurs.get(type) ?? []).filter((autre) => autre !== rappel),
                )
        },
        emettre(type, detail) {
            for (const rappel of ecouteurs.get(type) ?? []) {
                rappel({ detail })
            }
        },
        nombreDEcouteurs: () => [...ecouteurs.values()].reduce((total, liste) => total + liste.length, 0),
    }
}

/**
 * Une fenêtre où le test décide de tout : l'entrée `navigation`, les entrées
 * de requêtes que reçoivent les observateurs (en différé, comme le
 * navigateur, et toutes d'un coup à qui demande `buffered`), l'horloge, les
 * minuteries, qui ne partent qu'à leur échéance, et les images.
 */
const fenetreSimulee = ({ navigation, hash = '', stockageBloque = false } = {}) => {
    const observateurs = []
    const entreesPassees = []
    const images = []
    const minuteries = []
    let instant = 0

    class ObservateurSimule {
        constructor(rappel) {
            this.rappel = rappel
            this.coupe = false
        }

        observe(options) {
            this.options = options
            observateurs.push(this)

            if (options.buffered && entreesPassees.length > 0) {
                this.rappel({ getEntries: () => [...entreesPassees] })
            }
        }

        disconnect() {
            this.coupe = true
        }
    }

    const performance = {
        now: () => instant,
        getEntriesByType: (type) => (type === 'navigation' && navigation ? [navigation] : []),
        mark: vi.fn((nom) => ({ name: nom, startTime: instant })),
        measure: vi.fn(),
    }

    const stockage = {
        donnees: new Map(),
        getItem: vi.fn((cle) => {
            if (stockageBloque) {
                throw new DOMException('refusé', 'SecurityError')
            }

            return stockage.donnees.get(cle) ?? null
        }),
        setItem: vi.fn((cle, valeur) => {
            if (stockageBloque) {
                throw new DOMException('refusé', 'SecurityError')
            }

            stockage.donnees.set(cle, String(valeur))
        }),
        removeItem: vi.fn((cle) => stockage.donnees.delete(cle)),
    }

    const fenetre = {
        performance,
        PerformanceObserver: ObservateurSimule,
        location: { origin: ORIGINE, href: `${ORIGINE}/dashboard${hash}`, hash },
        localStorage: stockage,
        document,
        console: { log: vi.fn() },
        navigator: { clipboard: { writeText: vi.fn(async () => undefined) } },
        getSelection: () => window.getSelection(),
        requestAnimationFrame: (rappel) => images.push(rappel),
        setTimeout: (rappel, delai = 0) => minuteries.push({ rappel, echeance: instant + delai }),
    }

    return {
        fenetre,
        stockage,
        performance,
        avancerA: (millisecondes) => {
            instant = millisecondes
        },
        /** Peint une image : les rappels en attente partent, ceux qu'ils posent attendent la suivante. */
        peindre: () => images.splice(0).forEach((rappel) => rappel()),
        /** Les minuteries échues à l'instant courant partent, et celles qu'elles posent pour cet instant aussi. */
        laisserPasser: () => {
            for (
                let rang = minuteries.findIndex(({ echeance }) => echeance <= instant);
                rang !== -1;
                rang = minuteries.findIndex(({ echeance }) => echeance <= instant)
            ) {
                minuteries.splice(rang, 1)[0].rappel()
            }
        },
        /** De nouvelles entrées arrivent aux observateurs encore branchés. */
        recevoir: (...entrees) => {
            entreesPassees.push(...entrees)
            observateurs
                .filter((observateur) => !observateur.coupe)
                .forEach((observateur) => observateur.rappel({ getEntries: () => entrees }))
        },
        observateurs,
    }
}

const documentServi = (options = {}) => entreeDeRequete('/dashboard', { initiatorType: 'navigation', ...options })

/** Déplie le panneau comme un toucher sur son titre, et attend son évènement `toggle`, qui part après. */
const deplier = async (panneau) => {
    const bascule = new Promise((resoudre) => panneau.addEventListener('toggle', resoudre, { once: true }))

    panneau.querySelector('summary').click()
    await bascule
}

/** Une visite d'Inertia telle que ses évènements la portent. */
const visite = (adresse, options = {}) => ({
    id: options.id ?? 'visite-1',
    url: new URL(adresse, ORIGINE),
    method: 'get',
    only: [],
    except: [],
    reset: [],
    prefetch: false,
    async: false,
    ...options,
})

let installe = null

afterEach(() => {
    installe?.desinstaller()
    installe = null
    document.body.innerHTML = ''
    vi.restoreAllMocks()
})

describe('les pièces du relevé', () => {
    it('réduit une adresse à son chemin, sans origine, chaîne de requête ni fragment, chaque identifiant en {id}', () => {
        expect(cheminNormalise(`${ORIGINE}/workouts/123?page=2#series`, ORIGINE)).toBe('/workouts/{id}')
        expect(cheminNormalise('/api/v1/sets/42', ORIGINE)).toBe('/api/v1/sets/{id}')
        expect(cheminNormalise('/notifications/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/read', ORIGINE)).toBe(
            '/notifications/{id}/read',
        )
        expect(cheminNormalise('/workouts/en-attente/k-0f3a9c2e7b1d4a6f', ORIGINE)).toBe('/workouts/en-attente/{id}')
        expect(cheminNormalise('/body-metrics/chest', ORIGINE)).toBe('/body-metrics/chest')
        expect(cheminNormalise('/', ORIGINE)).toBe('/')
        expect(cheminNormalise('https://autre.example.org/workouts', ORIGINE)).toBeNull()
        expect(cheminNormalise('http://[nope', ORIGINE)).toBeNull()
    })

    it('lit les métriques du serveur et leurs nombres, et ignore le reste', () => {
        expect(
            tempsDuServeur([
                ...tempsDuServeurPoses({ app: 48.2, sql: 12.4, requetes: 7, cache: 1.9, lectures: 8 }),
                { name: 'cdn-cache', duration: 3, description: 'HIT' },
            ]),
        ).toEqual({
            app: 48.2,
            routage: 1,
            controleur: 20,
            rendu: 19,
            sql: 12.4,
            sqlNombre: 7,
            cache: 1.9,
            cacheNombre: 8,
        })
        expect(tempsDuServeur(undefined)).toEqual({})
        expect(tempsDuServeur([{ name: 'sql', duration: 1, description: '' }])).toEqual({ sql: 1 })
    })

    it('tire d’une entrée le TTFB, l’écart hors de l’application, les étapes du serveur et le passage par le worker', () => {
        expect(
            ligneDuReleve(
                entreeDeRequete('/stats', { requestStart: 100, responseStart: 175, workerStart: 92, fetchStart: 99 }),
                'GET /stats (visite)',
            ),
        ).toEqual({
            etiquette: 'GET /stats (visite)',
            ttfb: 75,
            horsDeLApp: 35,
            app: 40,
            routage: 1,
            controleur: 20,
            rendu: 19,
            sql: 12,
            requetesSql: 7,
            cache: 2,
            lecturesDuCache: 8,
            worker: 8,
            redirection: null,
        })

        // Sans worker, sans en-tête, ou envoyée pendant que le worker démarrait.
        const sansRien = ligneDuReleve(
            entreeDeRequete('/stats', { workerStart: 0, serverTiming: [] }),
            'GET /stats (visite)',
        )
        expect(sansRien).toMatchObject({
            ttfb: 60,
            horsDeLApp: null,
            app: null,
            routage: null,
            controleur: null,
            rendu: null,
            sql: null,
            lecturesDuCache: null,
            worker: null,
        })
        expect(ligneDuReleve(entreeDeRequete('/', { workerStart: 120 }), '').worker).toBeNull()
        expect(ligneDuReleve(entreeDeRequete('/', { requestStart: 0, responseStart: 0 }), '').ttfb).toBeNull()
        // Une redirection suivie : le temps de la requête de départ, de son envoi à la fin de la réponse qui redirige.
        expect(ligneDuReleve(entreeDeRequete('/', { redirectStart: 50, redirectEnd: 90 }), '').redirection).toBe(40)
    })

    it('prend la médiane et le p95 au rang le plus proche', () => {
        const vingt = Array.from({ length: 20 }, (_, rang) => rang + 1)

        expect(centile(vingt, 50)).toBe(10)
        expect(centile(vingt, 95)).toBe(19)
        expect(centile([3, 1, 2], 50)).toBe(2)
        expect(centile([3, 1, 2], 95)).toBe(3)
        expect(centile([5], 95)).toBe(5)
        expect(centile([null, 4, Number.NaN], 50)).toBe(4)
        expect(centile([], 50)).toBeNull()
    })

    it('rend un tableau Markdown par requête et par navigation, médiane puis p95', () => {
        const ligne = (ttfb, app) => ({
            etiquette: 'GET /dashboard (partielle)',
            ttfb,
            horsDeLApp: ttfb - app,
            app,
            routage: 1,
            controleur: app - 11,
            rendu: 10,
            sql: 10,
            requetesSql: 12,
            cache: 1.25,
            lecturesDuCache: 9,
            worker: null,
            redirection: null,
        })
        const ecritureRedirigee = {
            etiquette: 'POST /seances (visite) → redirigée',
            ttfb: 55,
            horsDeLApp: 11,
            app: 44,
            routage: 1,
            controleur: 30,
            rendu: 13,
            sql: 5,
            requetesSql: 9,
            cache: 1,
            lecturesDuCache: 6,
            worker: null,
            redirection: 40,
        }
        const tableau = tableauDuReleve({
            requetes: [ecritureRedirigee, ligne(60, 40), ligne(80, 50), ligne(70, 45)],
            navigations: [
                { chemin: '/stats', duree: 120 },
                { chemin: '/stats', duree: 80.04 },
                { chemin: '/dashboard', duree: 95 },
            ],
        })

        expect(tableau.split('\n')).toEqual([
            'Relevé des temps : chemins relatifs, millisecondes, médiane / p95.',
            'Une requête « → redirigée » porte les temps de sa requête d’arrivée ; « redirection » est le temps de la requête de départ, réponse qui redirige comprise.',
            '',
            '| Requête | n | TTFB | hors de l’app | app | routage | contrôleur | rendu | sql | requêtes SQL | cache | lectures du cache | worker | redirection |',
            '| --- | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: |',
            '| GET /dashboard (partielle) | 3 | 70 / 80 | 25 / 30 | 45 / 50 | 1 / 1 | 34 / 39 | 10 / 10 | 10 / 10 | 12 | 1,3 / 1,3 | 9 | — | — |',
            '| POST /seances (visite) → redirigée | 1 | 55 / 55 | 11 / 11 | 44 / 44 | 1 / 1 | 30 / 30 | 13 / 13 | 5 / 5 | 9 | 1 / 1 | 6 | — | 40 / 40 |',
            '',
            '| Navigation | n | médiane | p95 |',
            '| --- | --: | --: | --: |',
            '| /dashboard | 1 | 95 | 95 |',
            '| /stats | 2 | 80 | 120 |',
        ])
    })
})

describe('tant que le serveur ne mesure pas', () => {
    it('ne s’arme pas, ne pose rien et ne garde rien', () => {
        const simulee = fenetreSimulee({ navigation: documentServi({ serverTiming: [] }), hash: FRAGMENT_QUI_AFFICHE })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        simulee.recevoir(entreeDeRequete('/dashboard', { serverTiming: [] }))
        routeur.emettre('before', { visit: visite('/stats') })

        expect(routeur.nombreDEcouteurs()).toBe(0)
        expect(simulee.fenetre[FONCTION_DE_CONSOLE]).toBeUndefined()
        expect(simulee.performance.mark).not.toHaveBeenCalled()
        expect(simulee.stockage.setItem).not.toHaveBeenCalled()
        expect(document.getElementById('releve-des-temps')).toBeNull()
        expect(installe.releve()).toEqual({ requetes: [], navigations: [] })
        // Une seule requête regardée, et l'observateur coupé.
        expect(simulee.observateurs.every((observateur) => observateur.coupe)).toBe(true)
    })

    it('ne regarde que la première requête de la page : une suivante qui porterait l’en-tête ne l’arme plus', () => {
        const simulee = fenetreSimulee({ navigation: documentServi({ serverTiming: [] }) })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        // Un script ou une autre origine ne comptent pas comme une requête de la page.
        simulee.recevoir(
            entreeDeRequete('/build/assets/app.js', { initiatorType: 'script' }),
            entreeDeRequete('https://autre.example.org/x'),
        )
        expect(simulee.observateurs.some((observateur) => !observateur.coupe)).toBe(true)

        simulee.recevoir(entreeDeRequete('/sonde', { initiatorType: 'fetch', serverTiming: [] }))
        simulee.recevoir(entreeDeRequete('/stats'))

        expect(routeur.nombreDEcouteurs()).toBe(0)
        expect(installe.releve().requetes).toEqual([])
    })

    it('ne s’installe pas sans les API de mesure', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })

        expect(
            installerLeReleveDesTemps({
                routeur: routeurSimule(),
                fenetre: { ...simulee.fenetre, PerformanceObserver: undefined },
            }),
        ).toBeNull()
        expect(
            installerLeReleveDesTemps({
                routeur: routeurSimule(),
                fenetre: { ...simulee.fenetre, performance: undefined },
            }),
        ).toBeNull()
    })
})

describe('quand le serveur mesure', () => {
    it('s’arme sur le document servi, qu’il relève en premier', () => {
        const simulee = fenetreSimulee({ navigation: documentServi({ name: `${ORIGINE}/dashboard?onglet=1` }) })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })

        expect(installe.releve().requetes).toEqual([
            expect.objectContaining({ etiquette: 'GET /dashboard (document)', ttfb: 60 }),
        ])
        expect(routeur.nombreDEcouteurs()).toBe(4)
        expect(simulee.fenetre[FONCTION_DE_CONSOLE]).toBeTypeOf('function')
    })

    it('s’arme sur la première requête quand le document, rendu par le service worker, n’a pas gardé l’en-tête', () => {
        const simulee = fenetreSimulee({ navigation: documentServi({ serverTiming: [] }) })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        simulee.recevoir(entreeDeRequete('/dashboard'))

        expect(installe.releve().requetes.map((ligne) => ligne.etiquette)).toEqual([
            'GET /dashboard (document)',
            '? /dashboard',
        ])
        expect(routeur.nombreDEcouteurs()).toBe(4)
    })

    it('relève chaque requête de même origine faite par la page, et rien d’autre', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })
        simulee.recevoir(
            entreeDeRequete('/stats'),
            entreeDeRequete('/api/v1/sets', { initiatorType: 'fetch' }),
            entreeDeRequete('/build/assets/Stats.js', { initiatorType: 'script' }),
            entreeDeRequete('/fonts/inter.woff2', { initiatorType: 'css' }),
            entreeDeRequete('https://autre.example.org/stats'),
        )

        expect(installe.releve().requetes.map((ligne) => ligne.etiquette)).toEqual([
            'GET /dashboard (document)',
            '? /stats',
            '? /api/v1/sets',
        ])
    })

    it('nomme chaque requête par sa méthode et sa sorte, d’après ce que la page en annonce à son départ', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        routeur.emettre('start', { visit: visite('/stats?periode=mois#graphique') })
        routeur.emettre('start', { visit: visite('/dashboard', { only: ['analyticalStats'], async: true }) })
        routeur.emettre('start', { visit: visite('/workouts', { prefetch: true, async: true }) })
        routeur.emettre('start', { visit: visite('/habits/3/toggle', { method: 'post', async: true }) })
        routeur.emettre('start', { visit: visite('/workouts/5', { method: 'patch' }) })
        noterLaRequete('post', '/api/v1/sets')
        noterLaRequete('patch', `${ORIGINE}/api/v1/sets/9`)

        simulee.recevoir(
            entreeDeRequete('/api/v1/sets/9', { initiatorType: 'fetch' }),
            entreeDeRequete('/stats?periode=mois'),
            entreeDeRequete('/dashboard'),
            entreeDeRequete('/workouts'),
            entreeDeRequete('/habits/3/toggle'),
            entreeDeRequete('/workouts/5'),
            entreeDeRequete('/api/v1/sets', { initiatorType: 'fetch' }),
            entreeDeRequete('/notifications'),
        )

        expect(
            installe
                .releve()
                .requetes.map((ligne) => ligne.etiquette)
                .slice(1),
        ).toEqual([
            'PATCH /api/v1/sets/{id}',
            'GET /stats (visite)',
            'GET /dashboard (partielle)',
            'GET /workouts (préchargement)',
            'POST /habits/{id}/toggle (asynchrone)',
            'PATCH /workouts/{id} (visite)',
            'POST /api/v1/sets',
            '? /notifications',
        ])
    })

    it('apprend de `http()` la méthode d’une écriture, que son entrée ne dit pas', async () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })

        vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(null, { status: 204 }))
        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })
        await http.post(`${ORIGINE}/api/v1/sets`, { reps: 8 })
        await http.delete(`${ORIGINE}/api/v1/sets/41`)
        simulee.recevoir(
            entreeDeRequete('/api/v1/sets/41', { initiatorType: 'fetch' }),
            entreeDeRequete('/api/v1/sets', { initiatorType: 'fetch' }),
        )

        expect(
            installe
                .releve()
                .requetes.map((ligne) => ligne.etiquette)
                .slice(1),
        ).toEqual(['DELETE /api/v1/sets/{id}', 'POST /api/v1/sets'])
    })

    it('ne range pas sous une écriture redirigée les temps de la page d’arrivée, et garde ceux de sa redirection', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        routeur.emettre('start', { visit: visite('/seances', { method: 'post' }) })
        routeur.emettre('start', { visit: visite('/seances/12') })
        // Le XHR suit le 303 : l'entrée garde l'adresse de départ, mais ses instants et son en-tête sont ceux du GET d'arrivée.
        simulee.recevoir(
            entreeDeRequete('/seances', {
                redirectStart: 50,
                redirectEnd: 90,
                requestStart: 95,
                responseStart: 150,
                serverTiming: tempsDuServeurPoses({ app: 44 }),
            }),
            entreeDeRequete('/seances/12'),
        )

        expect(installe.releve().requetes.slice(1)).toEqual([
            expect.objectContaining({
                etiquette: 'POST /seances (visite) → redirigée',
                ttfb: 55,
                app: 44,
                redirection: 40,
            }),
            expect.objectContaining({ etiquette: 'GET /seances/{id} (visite)', redirection: null }),
        ])
        expect(simulee.fenetre[FONCTION_DE_CONSOLE]()).not.toMatch(/\| POST \/seances \(visite\) \|/)
    })

    it('garde au plus les dernières mesures', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })
        simulee.recevoir(
            ...Array.from({ length: MESURES_AU_PLUS + 50 }, (_, rang) =>
                entreeDeRequete('/stats', { requestStart: 1, responseStart: 2 + rang }),
            ),
        )

        const requetes = installe.releve().requetes

        // Le document et les cinquante premières requêtes sont partis.
        expect(requetes).toHaveLength(MESURES_AU_PLUS)
        expect(requetes[0].ttfb).toBe(51)
        expect(requetes.at(-1).ttfb).toBe(MESURES_AU_PLUS + 50)
    })

    it('rend un tableau qui ne dit ni l’origine, ni l’hôte, ni une chaîne de requête, ni un identifiant', () => {
        const simulee = fenetreSimulee({ navigation: documentServi({ name: `${ORIGINE}/workouts/123?seance=987` }) })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        routeur.emettre('start', { visit: visite('/workouts/123?page=2&recherche=dos') })
        simulee.recevoir(
            entreeDeRequete('/workouts/123?page=2&recherche=dos'),
            entreeDeRequete('/notifications/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/read', { initiatorType: 'fetch' }),
        )
        routeur.emettre('before', { visit: visite('/exercises/77', { id: 'v' }) })
        routeur.emettre('navigate', { type: 'visit', visitId: 'v', page: { url: '/exercises/77?onglet=historique' } })
        simulee.peindre()
        simulee.peindre()

        const tableau = simulee.fenetre[FONCTION_DE_CONSOLE]()

        expect(tableau).toContain('| GET /workouts/{id} (document) |')
        expect(tableau).toContain('| GET /workouts/{id} (visite) |')
        expect(tableau).toContain('| ? /notifications/{id}/read |')
        expect(tableau).toContain('| /exercises/{id} |')
        expect(tableau).not.toMatch(/https?:|example\.org|\?[^ ]|123|987|77|9b1deb4d|recherche|dos\b/)
        expect(simulee.fenetre.console.log).toHaveBeenCalledWith(tableau)
    })
})

describe('la navigation perçue', () => {
    const installerArme = () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })

        return { simulee, routeur }
    }

    it('marque le toucher, puis mesure jusqu’à la première image peinte après la nouvelle page', () => {
        const { simulee, routeur } = installerArme()

        simulee.avancerA(1000)
        routeur.emettre('before', { visit: visite('/workouts/12', { id: 'a' }) })
        simulee.avancerA(1060)
        routeur.emettre('navigate', { type: 'visit', visitId: 'a', page: { url: '/workouts/12' } })
        simulee.avancerA(1070)
        simulee.peindre()

        // Une image seulement : la nouvelle page n'est pas encore peinte.
        expect(installe.releve().navigations).toEqual([])

        simulee.avancerA(1085)
        simulee.peindre()

        expect(simulee.performance.mark).toHaveBeenCalledWith(MARQUE_DU_TOUCHER)
        expect(simulee.performance.measure).toHaveBeenCalledWith(`${PREFIXE_DES_NAVIGATIONS}/workouts/{id}`, {
            start: 1000,
            end: 1085,
        })
        expect(installe.releve().navigations).toEqual([{ chemin: '/workouts/{id}', duree: 85 }])
    })

    it('ne mesure ni un préchargement, ni une visite asynchrone, ni un rechargement partiel', () => {
        const { simulee, routeur } = installerArme()

        for (const options of [
            { prefetch: true, async: true },
            { async: true },
            { only: ['analyticalStats'], async: true },
            { except: ['auth'] },
            { reset: ['workouts'] },
        ]) {
            routeur.emettre('before', { visit: visite('/stats', { id: 'x', ...options }) })
            routeur.emettre('navigate', { type: 'visit', visitId: 'x', page: { url: '/stats' } })
            simulee.peindre()
            simulee.peindre()
        }

        expect(simulee.performance.mark).not.toHaveBeenCalled()
        expect(simulee.performance.measure).not.toHaveBeenCalled()
        expect(installe.releve().navigations).toEqual([])
    })

    it('ne mesure que la page de la visite marquée : ni le retour arrière, ni une autre visite', () => {
        const { simulee, routeur } = installerArme()

        routeur.emettre('before', { visit: visite('/stats', { id: 'marquee' }) })
        routeur.emettre('navigate', { type: 'history', visitId: 'h', page: { url: '/dashboard' } })
        routeur.emettre('navigate', { type: 'initial', visitId: 'i', page: { url: '/dashboard' } })
        routeur.emettre('navigate', { type: 'visit', visitId: 'une-autre', page: { url: '/habits' } })
        simulee.peindre()
        simulee.peindre()

        expect(installe.releve().navigations).toEqual([])

        routeur.emettre('navigate', { type: 'visit', visitId: 'marquee', page: { url: '/stats' } })
        // La même visite ne se mesure qu'une fois.
        routeur.emettre('navigate', { type: 'visit', visitId: 'marquee', page: { url: '/stats' } })
        simulee.peindre()
        simulee.peindre()

        expect(installe.releve().navigations.map((navigation) => navigation.chemin)).toEqual(['/stats'])
    })

    it('ne lève rien sans User Timing, et garde la durée', () => {
        const { simulee, routeur } = installerArme()

        simulee.performance.mark.mockImplementation(() => {
            throw new TypeError('performance.mark is not a function')
        })
        simulee.performance.measure.mockImplementation(() => {
            throw new TypeError('performance.measure is not a function')
        })

        simulee.avancerA(10)
        routeur.emettre('before', { visit: visite('/stats', { id: 'b' }) })
        simulee.avancerA(40)
        routeur.emettre('navigate', { type: 'visit', visitId: 'b', page: { url: '/stats' } })
        simulee.peindre()
        simulee.peindre()

        expect(installe.releve().navigations).toEqual([{ chemin: '/stats', duree: 30 }])
    })

    it('mesure au tour suivant quand la fenêtre n’a pas d’images à attendre', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({
            routeur,
            fenetre: { ...simulee.fenetre, requestAnimationFrame: undefined },
        })
        routeur.emettre('before', { visit: visite('/stats', { id: 'c' }) })
        routeur.emettre('navigate', { type: 'visit', visitId: 'c', page: { url: '/stats' } })

        expect(installe.releve().navigations).toHaveLength(0)

        simulee.laisserPasser()

        expect(installe.releve().navigations).toHaveLength(1)
    })
})

describe('le relevé à l’écran', () => {
    beforeEach(() => {
        window.localStorage.clear()
    })

    it('reste caché sans le drapeau local', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        expect(document.getElementById('releve-des-temps')).toBeNull()
        expect(simulee.stockage.setItem).not.toHaveBeenCalled()
    })

    it('s’affiche replié par le fragment, qui pose le drapeau, puis déplié suit les mesures', async () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        const panneau = document.getElementById('releve-des-temps')

        expect(simulee.stockage.donnees.get(DRAPEAU_DE_L_AFFICHAGE)).toBe('1')
        expect(panneau.tagName).toBe('DETAILS')
        expect(panneau.open).toBe(false)
        expect(panneau.querySelector('summary').textContent).toContain('rien ne quitte cet appareil')

        await deplier(panneau)

        expect(panneau.querySelector('pre').textContent).toContain('| GET /dashboard (document) | 1 |')

        simulee.recevoir(entreeDeRequete('/stats'))
        simulee.avancerA(RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS)
        simulee.laisserPasser()

        expect(panneau.querySelector('pre').textContent).toContain('| ? /stats | 1 |')
    })

    /*
     * Bâtir le tableau coûte, et une entrée arrive pendant qu'Inertia traite
     * la réponse d'une visite : recalculé à chaque entrée, le panneau
     * allongeait la navigation qu'il rapporte (#2148).
     */
    it('replié, ne recalcule ni ne réécrit rien quand des mesures arrivent', () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })

        const ecritures = new MutationObserver(() => {})
        const formateurs = vi.spyOn(Intl, 'NumberFormat')

        ecritures.observe(document.querySelector('#releve-des-temps pre'), {
            childList: true,
            characterData: true,
            subtree: true,
        })
        simulee.recevoir(...Array.from({ length: 20 }, (_, rang) => entreeDeRequete(`/stats/${rang}`)))
        routeur.emettre('before', { visit: visite('/stats', { id: 'r' }) })
        routeur.emettre('navigate', { type: 'visit', visitId: 'r', page: { url: '/stats' } })
        simulee.peindre()
        simulee.peindre()
        simulee.avancerA(10 * RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS)
        simulee.laisserPasser()

        expect(installe.releve().navigations).toHaveLength(1)
        expect(ecritures.takeRecords()).toEqual([])
        expect(formateurs).not.toHaveBeenCalled()

        ecritures.disconnect()
    })

    it('déplié, se recalcule au plus une fois par seconde, jamais dans le rappel qui apporte la mesure', async () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        const panneau = document.getElementById('releve-des-temps')
        const contenu = panneau.querySelector('pre')

        simulee.avancerA(100)
        await deplier(panneau)
        simulee.recevoir(entreeDeRequete('/stats'))

        expect(contenu.textContent).not.toContain('/stats')

        simulee.laisserPasser()
        simulee.avancerA(100 + RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS - 1)
        simulee.laisserPasser()

        expect(contenu.textContent).not.toContain('/stats')

        simulee.avancerA(100 + RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS)
        simulee.laisserPasser()

        expect(contenu.textContent).toContain('| ? /stats | 1 |')
    })

    it('déplié, attend pour se recalculer la fin de la navigation en cours, mesurée ou finie sans page', async () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })
        const routeur = routeurSimule()

        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })

        const panneau = document.getElementById('releve-des-temps')
        const contenu = panneau.querySelector('pre')

        await deplier(panneau)

        // Du `before` d'une visite au premier plan à sa mesure, une image après la page posée.
        simulee.avancerA(5000)
        routeur.emettre('before', { visit: visite('/workouts', { id: 'n' }) })
        simulee.recevoir(entreeDeRequete('/workouts'))
        simulee.avancerA(6000)
        simulee.laisserPasser()
        routeur.emettre('navigate', { type: 'visit', visitId: 'n', page: { url: '/workouts' } })
        simulee.laisserPasser()
        simulee.peindre()
        simulee.laisserPasser()

        expect(contenu.textContent).not.toContain('/workouts')

        simulee.peindre()
        simulee.laisserPasser()

        expect(contenu.textContent).toContain('| ? /workouts | 1 |')
        expect(contenu.textContent).toContain('| /workouts | 1 |')

        // Une visite vers la page courante n'émet pas de `navigate` : sa fin libère le recalcul.
        simulee.avancerA(8000)
        routeur.emettre('before', { visit: visite('/workouts', { id: 'p' }) })
        simulee.recevoir(entreeDeRequete('/habits'))
        simulee.avancerA(9000)
        simulee.laisserPasser()

        expect(contenu.textContent).not.toContain('/habits')

        routeur.emettre('finish', { visit: visite('/workouts', { id: 'p' }) })
        simulee.laisserPasser()

        expect(contenu.textContent).toContain('| ? /habits | 1 |')
    })

    it('s’affiche au chargement suivant, le drapeau posé', () => {
        const simulee = fenetreSimulee({ navigation: documentServi() })
        simulee.stockage.donnees.set(DRAPEAU_DE_L_AFFICHAGE, '1')

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        expect(document.getElementById('releve-des-temps')).not.toBeNull()
    })

    it('copie le tableau, ou le sélectionne quand le presse-papiers refuse', async () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        const [copier] = document.querySelectorAll('#releve-des-temps button')

        copier.click()
        await vi.waitFor(() => expect(copier.textContent).toBe('Copié'))
        expect(simulee.fenetre.navigator.clipboard.writeText).toHaveBeenCalledWith(
            simulee.fenetre[FONCTION_DE_CONSOLE](),
        )

        simulee.fenetre.navigator.clipboard.writeText.mockRejectedValueOnce(
            new DOMException('refusé', 'NotAllowedError'),
        )
        copier.click()
        await vi.waitFor(() => expect(copier.textContent).toBe('Sélectionné : copie-le'))
        expect(window.getSelection().toString()).toContain('Relevé des temps')
    })

    it('se ferme et retire le drapeau, sans cesser de relever', () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })
        document.querySelectorAll('#releve-des-temps button')[1].click()
        simulee.recevoir(entreeDeRequete('/stats'))

        expect(document.getElementById('releve-des-temps')).toBeNull()
        expect(simulee.stockage.donnees.has(DRAPEAU_DE_L_AFFICHAGE)).toBe(false)
        expect(installe.releve().requetes).toHaveLength(2)
    })

    /*
     * Le minuteur de repos se pose au bas de l'écran, et le README demande le
     * relevé de la séance en cours : posé à sa place et sur sa couche, le
     * panneau en cachait le bas. Déplié, il dépassait le haut d'un téléphone
     * en paysage, titre compris : plus rien ne le repliait, et « Fermer »
     * oublie le drapeau.
     */
    it('se tient en haut de l’écran, loin du minuteur de repos, et garde son titre à l’écran déplié', () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        const panneau = document.getElementById('releve-des-temps')
        const minuteur = readFileSync(resolve(jsRoot, 'Components/Workout/RestTimer.vue'), 'utf8')
            .match(/class="([^"]*\bfixed\b[^"]*)"/)[1]
            .split(/\s+/)
        const bords = (classes) => classes.filter((classe) => /^(?:[a-z]+:)*(?:top|bottom)-/.test(classe))
        const valeur = (classes, motif) => Number(classes.map((classe) => classe.match(motif)?.[1]).find(Boolean))

        expect(bords(minuteur)).toEqual(['bottom-sure-36'])
        expect(bords([...panneau.classList])).toEqual([expect.stringMatching(/^top-sure-\d+$/)])

        // Le titre d'abord, puis un corps borné à l'écran moins le haut du panneau et son titre, qui défile en lui-même.
        const [titre, corps] = panneau.children
        const haut = valeur([...panneau.classList], /^top-sure-(\d+)$/)
        const borne = valeur([...corps.classList], /^hauteur-sure-(\d+)$/)

        expect(titre.tagName).toBe('SUMMARY')
        expect(titre.classList).toContain('min-h-touch')
        // `min-h-touch` vaut 44 px, onze pas d'espacement.
        expect(borne).toBeGreaterThanOrEqual(haut + 11)
        expect(corps.querySelector('pre').classList).toContain('overflow-auto')
        // Les boutons avant le tableau : ils restent à portée, quelle que soit sa longueur.
        expect(corps.firstElementChild.querySelectorAll('button')).toHaveLength(2)
    })

    it('reste caché, sans lever, quand le stockage est bloqué', () => {
        const simulee = fenetreSimulee({
            navigation: documentServi(),
            hash: FRAGMENT_QUI_AFFICHE,
            stockageBloque: true,
        })

        installe = installerLeReleveDesTemps({ routeur: routeurSimule(), fenetre: simulee.fenetre })

        expect(document.getElementById('releve-des-temps')).toBeNull()
        expect(simulee.fenetre[FONCTION_DE_CONSOLE]).toBeTypeOf('function')
    })
})

describe('rien ne sort de l’appareil', () => {
    it('n’envoie rien et n’écrit rien, sinon le drapeau demandé', () => {
        const envois = vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.reject(new Error('envoi')))
        const ouvertures = vi.spyOn(XMLHttpRequest.prototype, 'open')
        const ecritures = vi.spyOn(Storage.prototype, 'setItem')
        const balise = vi.fn()
        const simulee = fenetreSimulee({ navigation: documentServi() })
        const routeur = routeurSimule()

        simulee.fenetre.navigator.sendBeacon = balise
        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        routeur.emettre('start', { visit: visite('/stats') })
        routeur.emettre('before', { visit: visite('/stats', { id: 'd' }) })
        simulee.recevoir(entreeDeRequete('/stats'))
        routeur.emettre('navigate', { type: 'visit', visitId: 'd', page: { url: '/stats' } })
        simulee.peindre()
        simulee.peindre()
        simulee.fenetre[FONCTION_DE_CONSOLE]()

        expect(installe.releve().navigations).toHaveLength(1)
        expect(envois).not.toHaveBeenCalled()
        expect(ouvertures).not.toHaveBeenCalled()
        expect(balise).not.toHaveBeenCalled()
        expect(ecritures).not.toHaveBeenCalled()
        expect(simulee.stockage.setItem).not.toHaveBeenCalled()
    })

    it('se retire en entier : écouteurs, observateur, fonction de console, panneau et annonces', () => {
        const simulee = fenetreSimulee({ navigation: documentServi(), hash: FRAGMENT_QUI_AFFICHE })
        const routeur = routeurSimule()
        // Une annonce de `http()` se lit contre l'adresse de la fenêtre : c'est par là qu'on la voit passer.
        const lecturesDeLAdresse = vi.fn(() => `${ORIGINE}/dashboard`)

        Object.defineProperty(simulee.fenetre.location, 'href', { get: lecturesDeLAdresse, configurable: true })
        installe = installerLeReleveDesTemps({ routeur, fenetre: simulee.fenetre })
        noterLaRequete('post', '/api/v1/sets')

        expect(lecturesDeLAdresse).toHaveBeenCalledTimes(1)

        installe.desinstaller()

        expect(routeur.nombreDEcouteurs()).toBe(0)
        expect(simulee.observateurs.every((observateur) => observateur.coupe)).toBe(true)
        expect(simulee.fenetre[FONCTION_DE_CONSOLE]).toBeUndefined()
        expect(document.getElementById('releve-des-temps')).toBeNull()

        // Retiré, le relevé n'apprend plus rien de `http()` : l'annonce ne lui parvient plus.
        noterLaRequete('post', '/api/v1/sets')

        expect(lecturesDeLAdresse).toHaveBeenCalledTimes(1)

        installe = null
    })
})
