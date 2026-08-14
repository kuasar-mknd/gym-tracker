import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassInput from '@/Components/UI/GlassInput.vue'

const mountInput = (props = {}, options = {}) =>
    mount(GlassInput, {
        props,
        global: { directives: { press: {} } },
        ...options,
    })

const input = (wrapper) => wrapper.find('input')
const clearButton = (wrapper) => wrapper.find('button[aria-label="Effacer le texte"]')
const passwordToggle = (wrapper) =>
    wrapper.findAll('button').find((button) => /mot de passe/i.test(button.attributes('aria-label') ?? ''))

describe('GlassInput — password visibility', () => {
    it('masks the value until the toggle is pressed, then unmasks it', async () => {
        const wrapper = mountInput({ type: 'password', modelValue: 'hunter2' })

        expect(input(wrapper).attributes('type')).toBe('password')
        expect(passwordToggle(wrapper).attributes('aria-label')).toBe('Afficher le mot de passe')

        await passwordToggle(wrapper).trigger('click')

        expect(input(wrapper).attributes('type')).toBe('text')
        expect(passwordToggle(wrapper).attributes('aria-label')).toBe('Masquer le mot de passe')

        await passwordToggle(wrapper).trigger('click')

        expect(input(wrapper).attributes('type')).toBe('password')
    })

    it('offers no visibility toggle on a field that is not a password', () => {
        const wrapper = mountInput({ type: 'text', modelValue: 'abc' })

        expect(passwordToggle(wrapper)).toBeUndefined()
    })
})

describe('GlassInput — clearing', () => {
    it('empties the bound value when the clear button is pressed', async () => {
        const wrapper = mountInput({ type: 'text', modelValue: 'whey' })

        await clearButton(wrapper).trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    })

    it('offers nothing to clear while the field is empty', () => {
        const wrapper = mountInput({ type: 'text', modelValue: '' })

        expect(clearButton(wrapper).exists()).toBe(false)
    })

    it('clears a numeric value the user actually typed', async () => {
        // A number arrives as a Number, whose `.length` is undefined: the guard
        // has to stringify before it decides there is something to clear.
        const wrapper = mountInput({ type: 'text', modelValue: 30 })

        await clearButton(wrapper).trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    })

    it('offers no clear button on a password field, which has its own control', () => {
        const wrapper = mountInput({ type: 'password', modelValue: 'hunter2' })

        expect(clearButton(wrapper).exists()).toBe(false)
        expect(passwordToggle(wrapper)).toBeDefined()
    })

    it('offers no clear button on a stepper, whose value is not free text', () => {
        const wrapper = mountInput({ type: 'number', modelValue: 30 })

        expect(clearButton(wrapper).exists()).toBe(false)
    })

    it('offers no clear button on a field the user may not write to', () => {
        const disabled = mountInput({ type: 'text', modelValue: 'whey' }, { attrs: { disabled: true } })
        const readonly = mountInput({ type: 'text', modelValue: 'whey' }, { attrs: { readonly: true } })

        expect(clearButton(disabled).exists()).toBe(false)
        expect(clearButton(readonly).exists()).toBe(false)
    })
})

describe('GlassInput — label and error wiring', () => {
    it('ties the label to the input it labels', () => {
        const wrapper = mountInput({ label: 'Nom', modelValue: '' })

        const id = input(wrapper).attributes('id')

        expect(id).toBeTruthy()
        expect(wrapper.find('label').attributes('for')).toBe(id)
    })

    it('gives two inputs on the same page distinct ids', () => {
        const first = mountInput({ label: 'Nom', modelValue: '' })
        const second = mountInput({ label: 'Marque', modelValue: '' })

        expect(input(first).attributes('id')).not.toBe(input(second).attributes('id'))
    })

    it('keeps the id the caller supplied rather than generating one', () => {
        const wrapper = mountInput({ label: 'Nom', modelValue: '' }, { attrs: { id: 'orm-weight' } })

        expect(input(wrapper).attributes('id')).toBe('orm-weight')
        expect(wrapper.find('label').attributes('for')).toBe('orm-weight')
    })

    it('keeps a hidden label in the accessibility tree instead of dropping it', () => {
        const wrapper = mountInput({ label: 'Recherche', hideLabel: true, modelValue: '' })

        const label = wrapper.find('label')

        expect(label.exists()).toBe(true)
        expect(label.text()).toContain('Recherche')
        expect(label.classes()).toContain('sr-only')
    })

    it('announces the error and points the input at the message that carries it', () => {
        const wrapper = mountInput({ label: 'Nom', error: 'Ce champ est requis.', modelValue: '' })

        const describedBy = input(wrapper).attributes('aria-describedby')

        expect(input(wrapper).attributes('aria-invalid')).toBe('true')
        expect(wrapper.get(`#${describedBy}`).text()).toContain('Ce champ est requis.')
    })

    it('reports a valid field as valid', () => {
        const wrapper = mountInput({ label: 'Nom', modelValue: 'Whey' })

        expect(input(wrapper).attributes('aria-invalid')).toBe('false')
    })

    it('marks a required field with an asterisk', () => {
        const required = mountInput({ label: 'Nom' }, { attrs: { required: true } })
        const optional = mountInput({ label: 'Marque' })

        expect(required.find('label').text()).toContain('*')
        expect(optional.find('label').text()).not.toContain('*')
    })
})

describe('GlassInput — typing and focus', () => {
    it('reports what the user typed', async () => {
        const wrapper = mountInput({ modelValue: '' })

        input(wrapper).element.value = '82.5'
        await input(wrapper).trigger('input')

        expect(wrapper.emitted('update:modelValue')).toEqual([['82.5']])
    })

    it('selects the existing value on focus when asked to, so it is typed over', async () => {
        const wrapper = mountInput({ modelValue: '80', selectOnFocus: true })
        const select = vi.spyOn(input(wrapper).element, 'select')

        await input(wrapper).trigger('focus')

        expect(select).toHaveBeenCalled()
    })

    it('leaves the caret alone on focus by default', async () => {
        const wrapper = mountInput({ modelValue: '80' })
        const select = vi.spyOn(input(wrapper).element, 'select')

        await input(wrapper).trigger('focus')

        expect(select).not.toHaveBeenCalled()
    })

    it('lets a parent drive the underlying field', () => {
        const wrapper = mountInput({ modelValue: '80' })
        const element = input(wrapper).element
        const focus = vi.spyOn(element, 'focus')
        const select = vi.spyOn(element, 'select')

        wrapper.vm.focus()
        wrapper.vm.select()

        expect(focus).toHaveBeenCalled()
        expect(select).toHaveBeenCalled()
    })
})

/**
 * Les trois défauts de #1385, chacun invisible depuis la forme d'appel courante.
 */
describe('GlassInput — ce que le bouton d’effacement croyait savoir', () => {
    /**
     * Un attribut booléen posé nu vaut la chaîne vide, jamais `true`.
     *
     * `!''` valant `true`, la garde `!attrs.readonly` laissait passer, et un
     * champ en lecture seule proposait un bouton pour l'effacer. Elle ne
     * marchait que si l'appelant écrivait `:readonly="true"` — ce que personne
     * n'écrit. Le composant était donc juste pour la forme d'appel que ses
     * tests employaient, et faux pour celle qu'emploient les pages.
     */
    it.each([
        ['readonly', ''],
        ['readonly', true],
        ['disabled', ''],
        ['disabled', true],
    ])('cache le bouton quand %s vaut %j', (attribute, value) => {
        const wrapper = mountInput({ type: 'text', modelValue: 'whey' }, { attrs: { [attribute]: value } })

        expect(clearButton(wrapper).exists()).toBe(false)
    })

    it('le montre quand ni readonly ni disabled ne sont posés', () => {
        const wrapper = mountInput({ type: 'text', modelValue: 'whey' })

        expect(clearButton(wrapper).exists()).toBe(true)
    })

    /**
     * Zéro est une valeur, pas une absence de valeur.
     *
     * `props.modelValue &&` court-circuitait avant d'atteindre le test de
     * longueur, si bien qu'un champ valant `0` — un poids, des répétitions, un
     * temps de repos — ne pouvait pas être vidé d'un clic.
     */
    it.each([
        [0, true],
        ['0', true],
        ['', false],
        [null, false],
        [undefined, false],
    ])('propose l’effacement pour %j : %s', (modelValue, expected) => {
        const wrapper = mountInput({ type: 'text', modelValue })

        expect(clearButton(wrapper).exists()).toBe(expected)
    })

    /**
     * `aria-describedby` était émis en permanence. Sans erreur, il désignait un
     * élément en `display: none` dont le seul contenu est une ligature
     * material-symbols, elle-même `aria-hidden` : un lecteur d'écran suivait
     * une référence vers du vide sur tous les champs sans erreur.
     */
    it('ne décrit le champ que lorsqu’il y a quelque chose à décrire', async () => {
        const wrapper = mountInput({ type: 'text', modelValue: 'whey' })

        expect(input(wrapper).attributes('aria-describedby')).toBeUndefined()

        await wrapper.setProps({ error: 'Trop court' })

        const described = input(wrapper).attributes('aria-describedby')

        expect(described).toBeTruthy()
        expect(wrapper.find(`#${described}`).text()).toContain('Trop court')
    })
})
