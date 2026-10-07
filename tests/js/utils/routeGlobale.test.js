import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { installerLaRouteGlobale } from '@/Utils/routeGlobale'
import { jsRoot } from '../conventions/sourceFiles'

/**
 * `@routes` n'écrit plus dans la page que la table des routes : la fonction
 * `route()` de Ziggy y arrivait en script en ligne de 21 Ko, dans chaque page
 * complète, alors que le bundle la porte déjà (#1969). Les `<script setup>`
 * l'appellent pourtant en globale, sans l'importer : c'est le bundle qui doit
 * la poser, et elle doit lire la table que la page déclare.
 */
describe('la fonction route() globale', () => {
    beforeEach(() => {
        delete globalThis.route

        // La forme de ce que `@routes` écrit dans la page, sans la fonction.
        globalThis.Ziggy = {
            url: 'https://gym.example.org',
            port: null,
            defaults: {},
            routes: {
                dashboard: { uri: 'dashboard', methods: ['GET', 'HEAD'] },
                'workouts.show': {
                    uri: 'workouts/{workout}',
                    methods: ['GET', 'HEAD'],
                    parameters: ['workout'],
                    bindings: { workout: 'id' },
                },
            },
        }
    })

    afterEach(() => {
        delete globalThis.route
        delete globalThis.Ziggy
    })

    it('résout une route nommée par la table globale, sans configuration passée', () => {
        installerLaRouteGlobale()

        expect(globalThis.route('dashboard')).toBe('https://gym.example.org/dashboard')
        expect(globalThis.route('dashboard', undefined, false)).toBe('/dashboard')
        expect(globalThis.route('workouts.show', { workout: 12 })).toBe('https://gym.example.org/workouts/12')
        expect(() => globalThis.route('filament.admin.home')).toThrow(/is not in the route list/)
    })

    it('est posée par main.js avant le montage de l’application', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLaRouteGlobale()')

        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})
