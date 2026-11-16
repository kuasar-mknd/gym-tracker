import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

const champNumerique = /(?<!`)<input\b[^>]*\btype="number"[^>]*>/gis

describe('les champs numériques écrits à la main', () => {
    /**
     * Sans `inputmode`, iOS ouvre le clavier alphabétique sur un `type="number"` :
     * la virgule d'un poids n'y est pas. `GlassInput` et `GlassBigNumber` le posent
     * d'eux-mêmes ; un `<input>` écrit à la main doit le dire (#2316).
     */
    it('déclarent tous leur clavier', () => {
        const fautifs = collectSourceFiles().flatMap((fichier) =>
            [...readFileSync(fichier, 'utf8').matchAll(champNumerique)]
                .filter((correspondance) => !/\binputmode=/.test(correspondance[0]))
                .map(
                    (correspondance) =>
                        `${fichier.replace(jsRoot, 'resources/js')}: ${correspondance[0].slice(0, 80)}…`,
                ),
        )

        expect(fautifs).toEqual([])
    })
})
