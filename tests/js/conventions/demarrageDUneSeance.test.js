import { describe, it, expect } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Démarrer une séance passe par la file hors ligne (#2049). Le bouton central
 * de la barre du bas, l'accueil, la liste des séances et leurs états vides
 * démarraient chacun par une visite d'Inertia vers `workouts.store` : sans
 * réseau, ils ne disaient que « Il faut le réseau pour démarrer une séance ».
 * Lancer un modèle faisait de même vers `templates.execute`.
 *
 * Une seule fonction connaît donc les routes du démarrage : un bouton qui
 * démarre une séance, ou qui lance un modèle, appelle `demarrer`
 * (`useDemarrageDUneSeance`). La file elle-même nomme la création d'une
 * séance pour un seul usage : la séance d'un modèle lancé sans réponse, mise
 * en file d'un seul coup avec ses lignes et ses séries
 * (`SyncService.mettreEnFileLaSeanceDUnModele`), que seul `demarrer` appelle.
 */
describe('démarrer une séance passe par useDemarrageDUneSeance', () => {
    it('ne nomme les routes du démarrage que dans useDemarrageDUneSeance, et dans la file pour la séance d’un modèle', () => {
        expect(filesMatching(/route\(\s*['"](api\.v1\.)?workouts\.store['"]/, { extensions: ['.vue', '.js'] })).toEqual(
            ['resources/js/Utils/SyncService.js', 'resources/js/composables/useDemarrageDUneSeance.js'],
        )
    })

    it('ne met en file la séance d’un modèle que depuis useDemarrageDUneSeance', () => {
        expect(filesMatching(/\.mettreEnFileLaSeanceDUnModele\(/, { extensions: ['.vue', '.js'] })).toEqual([
            'resources/js/composables/useDemarrageDUneSeance.js',
        ])
    })

    /*
     * Lancer un modèle passait par une visite d'Inertia (`templates.execute`),
     * qui ne disait sans réseau que « Il faut le réseau pour démarrer une
     * séance » et doublait une fin encore en file. La route web reste au
     * serveur ; aucune page ne la nomme plus.
     */
    it('ne lance plus un modèle par la route web du serveur', () => {
        expect(filesMatching(/['"]templates\.execute['"]/, { extensions: ['.vue', '.js'] })).toEqual([])
    })

    /**
     * `/workouts` reste l'adresse d'une page que la coquille et les pages
     * gardées nomment : seule celle de l'API se garde ici.
     */
    it('n’écrit l’adresse de l’API du démarrage en dur nulle part', () => {
        expect(filesMatching(/['"`]\/api\/v1\/workouts['"`]/, { extensions: ['.vue', '.js'] })).toEqual([])
    })
})
