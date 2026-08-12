import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassSelect from '@/Components/UI/GlassSelect.vue'

const mountSelect = (props = {}, attrs = {}) => mount(GlassSelect, { props, attrs })

const optionsOf = (wrapper) =>
    wrapper.findAll('option').map((option) => ({
        value: option.element.value,
        label: option.text(),
        disabled: option.element.disabled,
    }))

describe('GlassSelect', () => {
    it('pairs each value with its own label', () => {
        const wrapper = mountSelect({
            options: [
                { value: 'sedentary', label: 'Sédentaire' },
                { value: 'moderate', label: 'Modérément actif' },
            ],
            placeholder: '',
        })

        expect(optionsOf(wrapper)).toEqual([
            { value: 'sedentary', label: 'Sédentaire', disabled: false },
            { value: 'moderate', label: 'Modérément actif', disabled: false },
        ])
    })

    /**
     * Callers pass either shape. A bare string is its own value and its own
     * label.
     *
     * `option.element.value` alone cannot tell the two branches apart: with no
     * value attribute the DOM falls back to the option's own text, so reading
     * a string through the object branch — `option.value`, undefined — still
     * reads back as 'Pectoraux'. Only the attribute distinguishes them, and it
     * is the attribute that keeps working once a label stops being its value.
     */
    it('takes a bare string as both the value and the label', () => {
        const wrapper = mountSelect({ options: ['Pectoraux', 'Dos'], placeholder: '' })

        expect(optionsOf(wrapper)).toEqual([
            { value: 'Pectoraux', label: 'Pectoraux', disabled: false },
            { value: 'Dos', label: 'Dos', disabled: false },
        ])
        expect(wrapper.findAll('option').map((option) => option.attributes('value'))).toEqual(['Pectoraux', 'Dos'])
    })

    it('shows the current value as the selected option', () => {
        const wrapper = mountSelect({
            modelValue: 'moderate',
            options: [
                { value: 'light', label: 'Léger' },
                { value: 'moderate', label: 'Modéré' },
            ],
        })

        expect(wrapper.find('select').element.value).toBe('moderate')
    })

    it('emits the chosen value when the user picks another option', async () => {
        const wrapper = mountSelect({
            modelValue: 'light',
            options: [
                { value: 'light', label: 'Léger' },
                { value: 'very', label: 'Très actif' },
            ],
        })

        await wrapper.find('select').setValue('very')

        expect(wrapper.emitted('update:modelValue')).toEqual([['very']])
    })

    /**
     * The placeholder is a prompt, not a choice: leaving it selectable lets a
     * required field submit an empty value.
     */
    it('offers the placeholder as an unselectable prompt', () => {
        const wrapper = mountSelect({ placeholder: 'Choisis une catégorie', options: ['Dos'] })

        expect(optionsOf(wrapper)[0]).toEqual({ value: '', label: 'Choisis une catégorie', disabled: true })
    })

    it('drops the placeholder row when the caller blanks it', () => {
        const wrapper = mountSelect({ placeholder: '', options: ['Dos'] })

        expect(optionsOf(wrapper)).toHaveLength(1)
    })

    it('ties the label to the field it names', () => {
        const wrapper = mountSelect({ label: "Niveau d'activité", options: ['a'] })

        const selectId = wrapper.find('select').attributes('id')

        expect(selectId).toBeTruthy()
        expect(wrapper.find('label').attributes('for')).toBe(selectId)
        expect(wrapper.find('label').text()).toContain("Niveau d'activité")
    })

    it('uses the id the caller gave it rather than inventing one', () => {
        const wrapper = mountSelect({ label: 'Catégorie', options: ['a'] }, { id: 'exercise-category' })

        expect(wrapper.find('select').attributes('id')).toBe('exercise-category')
        expect(wrapper.find('label').attributes('for')).toBe('exercise-category')
    })

    /**
     * Two of these on one page — the app puts a category and a type select side
     * by side — must not answer to the same id, or the second label points at
     * the first field.
     */
    it('gives two unlabelled instances different ids', () => {
        const first = mountSelect({ options: ['a'] })
        const second = mountSelect({ options: ['a'] })

        expect(first.find('select').attributes('id')).not.toBe(second.find('select').attributes('id'))
    })

    it('marks the field invalid and points at the message when it is rejected', () => {
        const wrapper = mountSelect({ options: ['a'], error: 'Catégorie obligatoire' })

        const select = wrapper.find('select')

        expect(select.attributes('aria-invalid')).toBe('true')
        expect(wrapper.get(`#${select.attributes('aria-describedby')}`).text()).toContain('Catégorie obligatoire')
    })

    it('does not claim to be invalid when there is no error', () => {
        const wrapper = mountSelect({ options: ['a'] })

        expect(wrapper.find('select').attributes('aria-invalid')).toBe('false')
    })

    it('marks a required field for sighted users without announcing the asterisk twice', () => {
        const wrapper = mountSelect({ label: 'Catégorie', options: ['a'] }, { required: true })

        const marker = wrapper.find('label span')

        expect(marker.text()).toBe('*')
        expect(marker.attributes('aria-hidden')).toBe('true')
        expect(wrapper.find('select').attributes('required')).toBeDefined()
    })

    it('leaves an optional field unmarked', () => {
        const wrapper = mountSelect({ label: 'Catégorie', options: ['a'] })

        expect(wrapper.find('label span').exists()).toBe(false)
    })

    it('disables the field when asked', () => {
        const wrapper = mountSelect({ options: ['a'], disabled: true })

        expect(wrapper.find('select').element.disabled).toBe(true)
    })
})
