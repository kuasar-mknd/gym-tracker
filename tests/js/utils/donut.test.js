import { describe, it, expect } from 'vitest'
import { optionsDAnneau } from '@/Utils/donut'

/*
 * Ce que les deux anneaux du tableau de bord partagent.
 *
 * Ils portaient chacun leur copie des options, et elles avaient dérivé :
 * polices différentes, `layout.padding` sur un seul des deux. D'où deux
 * cercles de tailles visiblement différentes — mesurés dans le navigateur à
 * 172 px et 151 px de diamètre extérieur sur un écran de 375 px, contre
 * 119 px et 120 px après (#1316).
 */

describe('optionsDAnneau', () => {
    /*
     * La légende à droite mangeait la largeur — et pas la même selon la
     * longueur des étiquettes. C'est précisément ce qui rendait les deux
     * cercles inégaux : celui dont la légende était la plus large avait le
     * moins de place. En bas, les deux sont contraints par la même hauteur.
     */
    it('pose la légende en bas, pour que la largeur des étiquettes ne dicte pas la taille du cercle', () => {
        expect(optionsDAnneau(() => '').plugins.legend.position).toBe('bottom')
    })

    it('rend les mêmes options à chaque appel, hors infobulle', () => {
        // Deux anneaux, une seule description : c'est la duplication qui avait
        // laissé les deux dériver.
        const a = optionsDAnneau(() => 'a')
        const b = optionsDAnneau(() => 'b')

        expect(a.layout).toEqual(b.layout)
        expect(a.cutout).toBe(b.cutout)
        expect(a.plugins.legend).toEqual(b.plugins.legend)
    })

    it('confie l’étiquette de l’infobulle à l’appelant', () => {
        const options = optionsDAnneau((context) => `vu ${context.raw}`)

        expect(options.plugins.tooltip.callbacks.label({ raw: 7 })).toBe('vu 7')
    })
})
