import { describe, it, expect } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Démarrer une séance passe par la file hors ligne (#2049). Le bouton central
 * de la barre du bas, l'accueil, la liste des séances et leurs états vides
 * démarraient chacun par une visite d'Inertia vers `workouts.store` : sans
 * réseau, ils ne disaient que « Il faut le réseau pour démarrer une séance ».
 *
 * Une seule fonction connaît donc les routes du démarrage : un bouton qui
 * démarre une séance appelle `demarrer` (`useDemarrageDUneSeance`).
 */
describe('démarrer une séance passe par useDemarrageDUneSeance', () => {
    it('ne nomme les routes du démarrage que dans useDemarrageDUneSeance', () => {
        expect(filesMatching(/route\(\s*['"](api\.v1\.)?workouts\.store['"]/, { extensions: ['.vue', '.js'] })).toEqual(
            ['resources/js/composables/useDemarrageDUneSeance.js'],
        )
    })

    /**
     * `/workouts` reste l'adresse d'une page que la coquille et les pages
     * gardées nomment : seule celle de l'API se garde ici.
     */
    it('n’écrit l’adresse de l’API du démarrage en dur nulle part', () => {
        expect(filesMatching(/['"`]\/api\/v1\/workouts['"`]/, { extensions: ['.vue', '.js'] })).toEqual([])
    })
})
