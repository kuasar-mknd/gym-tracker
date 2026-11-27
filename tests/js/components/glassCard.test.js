import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassCard from '@/Components/UI/GlassCard.vue'

const MATERIAUX = ['verre-fin', 'verre-regulier', 'verre-epais']

const classesDe = (props) => mount(GlassCard, { props, slots: { default: 'contenu' } }).classes()

/**
 * Une carte porte un seul des trois matériaux du verre, nommé dans app.css : le
 * composant dit un rôle, jamais un flou ou une opacité.
 */
describe('GlassCard — les matériaux', () => {
    it.each([
        ['default', 'verre-regulier'],
        ['fin', 'verre-fin'],
        ['epais', 'verre-epais'],
    ])('la variante %s prend le matériau %s, et lui seul', (variant, materiau) => {
        const classes = classesDe({ variant })

        expect(classes).toContain(materiau)
        expect(classes.filter((classe) => MATERIAUX.includes(classe))).toEqual([materiau])
    })

    it('garde le verre régulier sous une surface pleine, qui ne floute plus', () => {
        const classes = classesDe({ variant: 'solid' })

        expect(classes).toContain('verre-regulier')
        expect(classes).toContain('backdrop-blur-none')
    })

    it('prend le rayon du rôle carte, sans valeur écrite', () => {
        expect(classesDe({})).toContain('rounded-carte')
    })

    it('ne porte plus ni halo coloré ni bordure irisée', () => {
        for (const variant of ['default', 'fin', 'epais', 'solid']) {
            const classes = classesDe({ variant })

            expect(classes.filter((classe) => /glow|iridescent/.test(classe))).toEqual([])
        }
    })
})
