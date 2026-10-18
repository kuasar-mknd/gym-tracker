import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Une prop différée que le réseau ne rend pas passe dans `rescuedProps`
 * (#2048, partie E, `Utils/visitesHorsLigne.js`), comme celles qu'une copie
 * hors ligne n'a pas. `<Deferred>` montre alors son créneau `#rescue` ; sans
 * lui, il retombe sur `#fallback`, un squelette qui pulse sans fin. Chaque
 * `<Deferred>` déclare donc son créneau `#rescue`, qui montre
 * `IndisponibleHorsLigne`.
 */
describe('chaque <Deferred> dit ce qui manque sans réseau', () => {
    const blocs = collectSourceFiles().flatMap((chemin) => {
        const source = readFileSync(chemin, 'utf8')
        const fichier = chemin.replace(jsRoot, 'resources/js')

        return [...source.matchAll(/<Deferred\s+:?data=[^>]*>([\s\S]*?)<\/Deferred>/g)].map(([, contenu]) => ({
            fichier,
            contenu,
        }))
    })

    it('trouve les <Deferred> des pages', () => {
        const ouvertures = collectSourceFiles().flatMap((chemin) => [
            ...readFileSync(chemin, 'utf8').matchAll(/<Deferred\s+:?data=/g),
        ])

        expect(blocs.length).toBe(ouvertures.length)
        expect(blocs.length).toBeGreaterThan(0)
    })

    it('donne à chacun un créneau #rescue qui montre IndisponibleHorsLigne', () => {
        const sansSecours = blocs
            .filter(({ contenu }) => !/<template #rescue>\s*<IndisponibleHorsLigne\b/.test(contenu))
            .map(({ fichier }) => fichier)

        expect(sansSecours).toEqual([])
    })
})
