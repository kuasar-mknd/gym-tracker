import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassButton from '@/Components/UI/GlassButton.vue'

const monter = (props = {}) => mount(GlassButton, { props, slots: { default: 'Enregistrer' } })

const classes = (wrapper) => wrapper.get('button').classes()

describe('GlassButton', () => {
    /**
     * Les WCAG 2.5.8 demandent 44 px. La petite taille en montrait 36 : au
     * doigt, elle attrapait le voisin. Elle en montre 40 et en offre 44 par un
     * pseudo-élément, qui déborde sans pousser ce qui l'entoure.
     */
    it.each([
        ['sm', 'min-h-10', 'before:-inset-0.5'],
        ['lg', 'min-h-13', null],
        ['xl', 'min-h-16', null],
    ])('donne au moins 44 px à la taille %s', (size, hauteur, debord) => {
        const rendu = classes(monter({ size }))

        expect(rendu).toContain(hauteur)

        if (debord !== null) {
            expect(rendu).toContain(debord)
        }
    })

    it('laisse la taille moyenne sur le jeton de la charte', () => {
        expect(classes(monter())).toContain('min-h-touch')
    })

    /**
     * L'action principale d'un formulaire se vise au pouce : elle prend la
     * largeur du téléphone, et retrouve la sienne dès qu'il y a la place.
     */
    it('ne prend toute la largeur que si on le demande', () => {
        expect(classes(monter())).not.toContain('w-full')
        expect(classes(monter({ block: true }))).toEqual(expect.arrayContaining(['w-full', 'sm:w-auto']))
    })
})
