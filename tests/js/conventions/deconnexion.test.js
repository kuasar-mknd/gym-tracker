import { describe, it, expect } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Se déconnecter détache d'abord l'appareil du compte, pour que ses
 * notifications cessent d'y arriver (#1926). Un lien Inertia vers `logout`
 * partirait sans attendre ce détachement : le menu du layout, le profil et la
 * page de vérification de l'adresse en portaient chacun un, et l'appareil
 * restait abonné au compte parti, records et rappels compris.
 *
 * Une seule fonction connaît donc la route : un bouton de déconnexion appelle
 * `seDeconnecter`.
 */
describe('la déconnexion passe par seDeconnecter', () => {
    it('ne nomme la route de déconnexion que dans useDeconnexion', () => {
        expect(filesMatching(/route\(\s*['"]logout['"]/, { extensions: ['.vue', '.js'] })).toEqual([
            'resources/js/composables/useDeconnexion.js',
        ])
    })

    it('n’écrit l’adresse de déconnexion en dur nulle part', () => {
        expect(filesMatching(/['"`]\/logout['"`]/, { extensions: ['.vue', '.js'] })).toEqual([])
    })
})
