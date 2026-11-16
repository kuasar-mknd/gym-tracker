import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'

const monter = (props = {}, attrs = {}) =>
    mount(GlassInput, { props, attrs, global: { directives: { press: {} } } }).get('input')

describe('GlassInput — clavier d’un champ numérique', () => {
    it('ouvre le clavier des entiers sans pas, ou avec un pas entier', () => {
        expect(monter({ type: 'number' }).attributes('inputmode')).toBe('numeric')
        expect(monter({ type: 'number' }, { step: '1' }).attributes('inputmode')).toBe('numeric')
    })

    it('ouvre le pavé décimal quand le pas est fractionnaire ou libre', () => {
        expect(monter({ type: 'number' }, { step: '0.5' }).attributes('inputmode')).toBe('decimal')
        expect(monter({ type: 'number' }, { step: 'any' }).attributes('inputmode')).toBe('decimal')
    })

    it('laisse l’appelant imposer le sien, et ne touche pas aux autres types', () => {
        expect(monter({ type: 'number' }, { inputmode: 'decimal' }).attributes('inputmode')).toBe('decimal')
        expect(monter({ type: 'text' }).attributes('inputmode')).toBeUndefined()
    })
})

describe('GlassBigNumber — clavier', () => {
    it('ouvre le pavé décimal : un disque de 1,25 kg existe', () => {
        const champ = mount(GlassBigNumber, { props: { label: 'Poids' } }).get('input')

        expect(champ.attributes('inputmode')).toBe('decimal')
    })

    it('donne le clavier des entiers à un pas entier, et laisse l’appelant imposer le sien', () => {
        const champ = (attrs) => mount(GlassBigNumber, { props: { label: 'Réps' }, attrs }).get('input')

        expect(champ({ step: '1' }).attributes('inputmode')).toBe('numeric')
        expect(champ({ step: '0.5' }).attributes('inputmode')).toBe('decimal')
        expect(champ({ inputmode: 'numeric' }).attributes('inputmode')).toBe('numeric')
    })
})
