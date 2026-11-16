import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { jsRoot } from './sourceFiles'

const source = (chemin) => readFileSync(resolve(jsRoot, chemin), 'utf8')

describe('les champs de saisie corrigés par #2316', () => {
    it('le poids d’une série de modèle accepte le centième de kilo, sur 44 px', () => {
        const champs = source('Components/Templates/TemplateForm.vue').match(/<input\b[^>]*type="number"[^>]*>/gs)
        const poids = champs.find((champ) => champ.includes('inputmode="decimal"'))

        expect(poids).toContain('step="0.01"')
        for (const champ of champs) {
            expect(champ).toContain('h-11')
            expect(champ).not.toMatch(/\bh-10\b/)
        }
    })

    it('la taille du calculateur de macros garde son séparateur décimal', () => {
        const taille = source('Pages/Tools/MacroCalculator.vue').match(
            /<GlassInput[^>]*v-model="form.height"[^>]*>/s,
        )[0]

        expect(taille).toContain('inputmode="decimal"')
    })
})
