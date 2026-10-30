import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { nomAccessible } from '../utils/nomAccessible'

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

/**
 * Une navigation qui ressemble à un bouton (#2117). Posé dans un `Link`, le
 * bouton rendait un `<button>` dans un `<a href>` : deux arrêts de tabulation
 * pour un seul geste, et un contenu interactif imbriqué que le HTML interdit.
 */
describe('GlassButton — en lien', () => {
    const lien = (props = {}, contenu = 'Calendrier') =>
        mount(GlassButton, {
            props: { href: '/calendar', ...props },
            slots: { default: contenu },
            attachTo: document.body,
        })

    it('se rend en un seul lien, sans bouton dedans', () => {
        const wrapper = lien()

        expect(wrapper.element.tagName).toBe('A')
        expect(wrapper.attributes('href')).toBe('/calendar')
        expect(wrapper.find('button').exists()).toBe(false)
        expect(document.querySelectorAll('a[href] button, a[href] a')).toHaveLength(0)

        wrapper.unmount()
    })

    it('garde les classes du bouton et ne porte rien qu’un lien ne sait porter', () => {
        const wrapper = lien({ variant: 'primary' })
        const commeBouton = mount(GlassButton, { props: { variant: 'primary' }, slots: { default: 'Calendrier' } })

        expect(wrapper.classes()).toEqual(commeBouton.get('button').classes())
        expect(wrapper.attributes('type')).toBeUndefined()
        expect(wrapper.attributes('disabled')).toBeUndefined()
        expect(wrapper.attributes('aria-busy')).toBeUndefined()

        wrapper.unmount()
    })

    it('garde son nom, donné par son texte ou par aria-label', () => {
        const parTexte = lien()
        const parEtiquette = mount(GlassButton, {
            props: { href: '/templates/create' },
            attrs: { 'aria-label': 'Nouveau modèle' },
            slots: { default: '<span class="material-symbols-outlined" aria-hidden="true">add</span>' },
            attachTo: document.body,
        })

        expect(nomAccessible(parTexte.element)).toBe('Calendrier')
        expect(nomAccessible(parEtiquette.element)).toBe('Nouveau modèle')

        parTexte.unmount()
        parEtiquette.unmount()
    })

    it('reste un bouton sans adresse', () => {
        expect(mount(GlassButton, { slots: { default: 'Enregistrer' } }).element.tagName).toBe('BUTTON')
    })
})
