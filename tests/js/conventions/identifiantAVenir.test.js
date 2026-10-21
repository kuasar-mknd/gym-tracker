import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { A_VENIR } from '../utils/fileHorsLigne'
import { MOTIF_D_UN_IDENTIFIANT } from '../composables/seanceHorsLigne'

/**
 * L'identifiant à venir de la file hors ligne passe par Ziggy (#2049).
 *
 * L'adresse de rattrapage (`ajusterPar`) ou d'annulation (`aAnnuler`) d'une
 * création se construit avec `route()` avant que le serveur ait donné
 * l'identifiant : `ID_A_VENIR` en tient la place, et le vidage le remplace.
 * Le serveur pose sur chaque paramètre de modèle le motif d'un identifiant
 * (`App\Support\IdentifiantsDesRoutes::MOTIF`), Ziggy le reçoit avec la table
 * des routes et lève une erreur pour un paramètre qui ne le suit pas.
 * `__produit__` ne le suivait pas : plus aucune série ne s'ajoutait, en ligne
 * comme sans réseau, et le stub des tests, qui ne vérifiait rien, ne l'a pas
 * vu.
 *
 * Les tests recopient l'identifiant à venir et le motif (le module de la file
 * ne s'importe qu'une fois le stockage rangé) : ces copies sont les leurs.
 */

const racine = resolve(__dirname, '../../..')
const lire = (chemin) => readFileSync(resolve(racine, chemin), 'utf8')

/** Le motif que le serveur pose sur chaque paramètre de modèle. */
const motifDuServeur = () => {
    const trouve = lire('app/Support/IdentifiantsDesRoutes.php').match(/const string MOTIF = '([^']+)';/)

    expect(trouve, 'IdentifiantsDesRoutes::MOTIF introuvable').not.toBeNull()

    return trouve[1]
}

/** L'identifiant à venir, tel que `Utils/SyncService` l'exporte. */
const identifiantAVenir = () => {
    const trouve = lire('resources/js/Utils/SyncService.js').match(/export const ID_A_VENIR = '([^']+)'/)

    expect(trouve, 'ID_A_VENIR introuvable dans Utils/SyncService.js').not.toBeNull()

    return trouve[1]
}

describe('l’identifiant à venir de la file', () => {
    it('suit le motif que le serveur pose sur un paramètre de modèle, que Ziggy exige', () => {
        expect(new RegExp(`^(?:${motifDuServeur()})$`).test(identifiantAVenir())).toBe(true)
    })

    it('ne peut être l’identifiant d’aucune ligne : au-delà du plus grand entier qu’une colonne de MySQL range', () => {
        expect(BigInt(identifiantAVenir()) > 18_446_744_073_709_551_615n).toBe(true)
    })

    it('est celui que les tests recopient, et le motif des tests est celui du serveur', () => {
        expect(A_VENIR).toBe(identifiantAVenir())
        expect(MOTIF_D_UN_IDENTIFIANT).toBe(motifDuServeur())
    })
})
