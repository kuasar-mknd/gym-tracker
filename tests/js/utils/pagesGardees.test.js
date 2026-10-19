import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import {
    PAGES_GARDEES,
    PROPS_JAMAIS_GARDEES,
    VERSION_DU_CONTRAT,
    cleDeLaCopie,
    cleDeLaSeanceEnCours,
    cleGardee,
    pageGardee,
    propJamaisGardee,
    propsGardables,
} from '@/Utils/pagesGardees'

/**
 * La liste blanche des pages gardées pour la salle (#2048) et la clé d'une
 * copie. Que chaque chemin soit celui de sa route, et qu'aucune page écartée
 * n'y entre, se vérifie côté serveur (`PagesGardeesTest`,
 * `LesPagesPriveesNeSontJamaisGardeesTest`).
 */
describe('la clé d’une copie', () => {
    it('réécrit la racine en accueil, comme le serveur la renvoie', () => {
        expect(cleDeLaCopie('/')).toBe('/dashboard')
        expect(cleDeLaCopie('/?b=2&a=1')).toBe('/dashboard?a=1&b=2')
    })

    it('retire la première page de la liste, et garde les autres', () => {
        expect(cleDeLaCopie('/workouts?page=1')).toBe('/workouts')
        expect(cleDeLaCopie('/workouts?page=2')).toBe('/workouts?page=2')
    })

    it('trie les paramètres par nom puis par valeur', () => {
        expect(cleDeLaCopie('/exercises?category=Dos&a=2&a=1')).toBe('/exercises?a=1&a=2&category=Dos')
        expect(cleDeLaCopie('/exercises?b=1&a=1')).toBe(cleDeLaCopie('/exercises?a=1&b=1'))
    })

    it('retire la barre finale et le fragment', () => {
        expect(cleDeLaCopie('/workouts/4/')).toBe('/workouts/4')
        expect(cleDeLaCopie('/tools#minuteur')).toBe('/tools')
    })

    it('n’admet une adresse absolue que de l’origine donnée', () => {
        expect(cleDeLaCopie('https://app.example.org/workouts/4', 'https://app.example.org')).toBe('/workouts/4')
        expect(cleDeLaCopie('https://autre.example.org/workouts/4', 'https://app.example.org')).toBeNull()
        expect(cleDeLaCopie('https://app.example.org/workouts/4')).toBeNull()
        expect(cleDeLaCopie('//autre.example.org/workouts/4', 'https://app.example.org')).toBeNull()
        expect(cleDeLaCopie('//autre.example.org/workouts/4')).toBeNull()
    })

    it('ne donne rien pour ce qui n’est pas une adresse de page', () => {
        expect(cleDeLaCopie(undefined)).toBeNull()
        expect(cleDeLaCopie(42)).toBeNull()
        expect(cleDeLaCopie('workouts')).toBeNull()
        expect(cleDeLaCopie('javascript:alert(1)', 'https://app.example.org')).toBeNull()
        expect(cleDeLaCopie('http://[', 'https://app.example.org')).toBeNull()
    })
})

describe('une page gardée', () => {
    it('se reconnaît à son chemin et à son composant', () => {
        expect(pageGardee('/dashboard', 'Dashboard')).toEqual({
            composant: 'Dashboard',
            route: 'dashboard',
            chemin: '/dashboard',
        })
        expect(pageGardee('/workouts/4', 'Workouts/Show')?.route).toBe('workouts.show')
        expect(pageGardee('/tools/1rm', 'Tools/OneRepMax')?.route).toBe('tools.1rm')
    })

    it('n’est pas gardée quand l’adresse a rendu une autre page', () => {
        expect(pageGardee('/dashboard', 'Auth/Login')).toBeNull()
        expect(pageGardee('/workouts/4', 'Workouts/Index')).toBeNull()
    })

    it('ne se garde que dans sa vue par défaut, sans filtre ni autre page', () => {
        expect(pageGardee('/workouts?page=2', 'Workouts/Index')).toBeNull()
        expect(pageGardee('/exercises?category=Dos', 'Exercises/Index')).toBeNull()
    })

    it('n’admet qu’un identifiant de séance', () => {
        expect(pageGardee('/workouts/0', 'Workouts/Show')).toBeNull()
        expect(pageGardee('/workouts/abc', 'Workouts/Show')).toBeNull()
        expect(pageGardee('/workouts/4/edit', 'Workouts/Show')).toBeNull()
        expect(pageGardee('/workouts/{workout}', 'Workouts/Show')).toBeNull()
        expect(pageGardee(null, 'Workouts/Show')).toBeNull()
    })

    it('ne reconnaît pas un chemin qui n’est pas sous sa forme normale', () => {
        expect(pageGardee(cleDeLaCopie('/workouts/../dashboard'), 'Dashboard')).toBeNull()
        expect(pageGardee(cleDeLaCopie('/workouts/4/..'), 'Workouts/Index')).toBeNull()
    })

    it('ne lit pas un chemin comme une expression', () => {
        expect(pageGardee('/toolsX1rm', 'Tools/OneRepMax')).toBeNull()
        expect(pageGardee('/tools/1rmm', 'Tools/OneRepMax')).toBeNull()
    })
})

/*
 * Ce que le worker lit d'une navigation, avant toute réponse : le chemin seul
 * (#2048, partie F). Il n'attend huit secondes que ces pages-là.
 */
describe('une clé de la liste, sans composant', () => {
    it.each([
        '/dashboard',
        '/workouts/12',
        '/workouts',
        '/templates',
        '/exercises',
        '/profile',
        '/tools',
        '/tools/interval-timer',
        '/plates',
        '/tools/1rm',
        '/tools/warmup',
    ])('reconnaît %s', (cle) => {
        expect(cleGardee(cle)).toBe(true)
    })

    it('reconnaît la racine et la première page de la liste, par leur clé', () => {
        expect(cleGardee(cleDeLaCopie('/'))).toBe(true)
        expect(cleGardee(cleDeLaCopie('/workouts?page=1'))).toBe(true)
    })

    it.each([
        ['une autre page', '/stats'],
        ['une page de modification', '/workouts/12/edit'],
        ['un identifiant qui n’en est pas un', '/workouts/0'],
        // Une séance démarrée sans réseau (#2049) : construite depuis la file, jamais copiée.
        ['une séance en attente de sa création', '/workouts/en-attente/0b6f5d1e-2049-4c11-9d1a-000000000001'],
        ['un modèle à modifier', '/templates/3/edit'],
        ['un nouveau modèle', '/templates/create'],
        ['une autre page de la liste', '/workouts?page=2'],
        ['un filtre', '/exercises?category=jambes'],
        ['rien', null],
    ])('écarte %s', (_, cle) => {
        expect(cleGardee(cle)).toBe(false)
    })
})

describe('la séance en cours', () => {
    it('se lit dans le compte que la page déclare', () => {
        expect(cleDeLaSeanceEnCours({ props: { auth: { user: { active_workout: { id: 12 } } } } })).toBe('/workouts/12')
    })

    it('n’existe pas sans séance ouverte', () => {
        expect(cleDeLaSeanceEnCours({ props: { auth: { user: { active_workout: null } } } })).toBeNull()
        expect(cleDeLaSeanceEnCours({ props: { auth: { user: null } } })).toBeNull()
        expect(cleDeLaSeanceEnCours({ props: { auth: { user: { active_workout: { id: '12' } } } } })).toBeNull()
        expect(cleDeLaSeanceEnCours(null)).toBeNull()
    })
})

describe('les props qu’aucune copie ne garde', () => {
    it('écartent le dernier poids et les objectifs de l’accueil, et rien d’autre', () => {
        expect(propJamaisGardee('Dashboard', 'latestWeight')).toBe(true)
        expect(propJamaisGardee('Dashboard', 'activeGoals')).toBe(true)
        expect(propJamaisGardee('Dashboard', 'recentWorkouts')).toBe(false)
        expect(propJamaisGardee('Workouts/Show', 'latestWeight')).toBe(false)
        expect(
            propsGardables('Dashboard', {
                auth: { user: { id: 7 } },
                latestWeight: '78.40',
                activeGoals: [],
                recentPRs: [],
            }),
        ).toEqual({ auth: { user: { id: 7 } }, recentPRs: [] })
        expect(propsGardables('Tools/Index', { latestWeight: '78.40' })).toEqual({ latestWeight: '78.40' })
        expect(propsGardables('Dashboard', undefined)).toEqual({})
    })

    it('ne lisent pas un composant comme une clé héritée', () => {
        for (const composant of ['__proto__', 'constructor', 'toString', 'hasOwnProperty', null, 42]) {
            expect(propJamaisGardee(composant, 'includes')).toBe(false)
            expect(propsGardables(composant, { includes: 1 })).toEqual({ includes: 1 })
        }
    })
})

describe('le module', () => {
    it('ne change pas une fois chargé', () => {
        expect(Object.isFrozen(PAGES_GARDEES)).toBe(true)
        expect(PAGES_GARDEES.every((page) => Object.isFrozen(page))).toBe(true)
        expect(Object.isFrozen(PROPS_JAMAIS_GARDEES)).toBe(true)
        expect(Object.values(PROPS_JAMAIS_GARDEES).every((noms) => Object.isFrozen(noms))).toBe(true)
        expect(Number.isInteger(VERSION_DU_CONTRAT) && VERSION_DU_CONTRAT > 0).toBe(true)
    })

    it('n’importe rien, pour que le worker et vite.config.js le lisent sans Vue ni Inertia', () => {
        const source = readFileSync(resolve(__dirname, '../../../resources/js/Utils/pagesGardees.js'), 'utf8')

        expect(source).not.toMatch(/^\s*import\b/m)
        expect(source).not.toMatch(/\bimport\s*\(/)
        expect(source).not.toMatch(/\brequire\s*\(/)
    })
})
