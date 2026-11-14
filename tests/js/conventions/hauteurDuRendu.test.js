import { describe, it, expect } from 'vitest'

import { hauteurDuRendu } from './ciblesTactiles'

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
})
