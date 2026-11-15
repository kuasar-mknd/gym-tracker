import { describe, it, expect } from 'vitest'

import { hauteurDuRendu, largeurDuRendu } from './ciblesTactiles'

const rendu = (classes) => {
    const element = document.createElement('button')
    element.setAttribute('class', classes)

    return element
}

describe('hauteurDuRendu', () => {
    it('compte le débord du pseudo-élément à partir du bord intérieur, bordure déduite', () => {
        expect(hauteurDuRendu(rendu('h-6 border before:-inset-y-3'))).toBe(46)
        expect(hauteurDuRendu(rendu('h-6 before:-inset-y-3'))).toBe(48)
    })

    it('ajoute la bordure à une boîte de hauteur automatique', () => {
        expect(hauteurDuRendu(rendu('py-2 border-2 text-base'))).toBe(hauteurDuRendu(rendu('py-2 text-base')) + 4)
    })

    it('lit les bordures d’un seul côté et celles en pixels, jamais les couleurs', () => {
        expect(hauteurDuRendu(rendu('h-6 border-t before:-inset-y-3'))).toBe(47)
        expect(hauteurDuRendu(rendu('h-6 border-y-2 before:-inset-y-3'))).toBe(44)
        expect(hauteurDuRendu(rendu('h-6 border-[3px] before:-inset-y-3'))).toBe(42)
        expect(hauteurDuRendu(rendu('h-6 border-transparent before:-inset-y-3'))).toBe(48)
        expect(hauteurDuRendu(rendu('py-2 border-b-2 text-base'))).toBe(hauteurDuRendu(rendu('py-2 text-base')) + 2)
    })

    it('compte la largeur sans les bordures de côté', () => {
        expect(largeurDuRendu(rendu('w-9 border-x-2 before:-inset-x-1.5'))).toBe(44)
        expect(largeurDuRendu(rendu('w-9 border-y-2 before:-inset-x-1.5'))).toBe(48)
    })
})
