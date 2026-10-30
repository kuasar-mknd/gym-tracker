import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { nomAccessible } from './nomAccessible'

/** Le calcul réduit du nom accessible, sur les formes que le dépôt emploie (#2115). */
describe('nomAccessible', () => {
    const lien = (corps, attributs = '') =>
        mount({
            components: { GlassIcon },
            template: `<div><span id="titre">Mes outils</span><a href="/" ${attributs}>${corps}</a></div>`,
        }).get('a').element

    it('ne lit pas la ligature d’une icône décorative', () => {
        expect(nomAccessible(lien('<GlassIcon name="arrow_back" />'))).toBe('')
    })

    it('lit l’icône qui porte un nom', () => {
        expect(nomAccessible(lien('<GlassIcon name="arrow_back" label="Retour" />'))).toBe('Retour')
    })

    it('préfère aria-label au texte, et aria-labelledby à aria-label', () => {
        expect(nomAccessible(lien('Texte', 'aria-label="Retour aux outils"'))).toBe('Retour aux outils')
        expect(nomAccessible(lien('Texte', 'aria-label="Retour" aria-labelledby="titre"'))).toBe('Mes outils')
    })

    it('lit le texte caché à l’œil, pas celui caché à tous', () => {
        expect(
            nomAccessible(
                lien('<span class="sr-only">Accueil</span><span aria-hidden="true">🏠</span><span hidden>x</span>'),
            ),
        ).toBe('Accueil')
    })
})
