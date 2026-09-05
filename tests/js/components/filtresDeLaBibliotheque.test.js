import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import FiltresDeLaBibliotheque from '@/Components/Exercises/FiltresDeLaBibliotheque.vue'

const monter = (props = {}) =>
    mount(FiltresDeLaBibliotheque, {
        props,
        attachTo: document.body,
        global: { directives: { press: {} } },
    })

afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
})

describe('FiltresDeLaBibliotheque', () => {
    it('rend la recherche à la page à chaque frappe, sans la garder pour lui', async () => {
        const wrapper = monter({ recherche: 'trac' })

        expect(wrapper.get('#search-exercises-input').element.value).toBe('trac')

        await wrapper.get('#search-exercises-input').setValue('tractions')

        expect(wrapper.emitted('update:recherche')).toEqual([['tractions']])
        wrapper.unmount()
    })

    it('propose « Tous » et chaque catégorie, en marquant seulement celle qui est active', async () => {
        const wrapper = monter({ categorie: 'Dos' })

        expect(wrapper.get('[dusk="category-pill-Dos"]').attributes('aria-pressed')).toBe('true')
        expect(wrapper.get('[dusk="category-pill-all"]').attributes('aria-pressed')).toBe('false')
        expect(wrapper.findAll('[aria-pressed="true"]')).toHaveLength(1)

        await wrapper.get('[dusk="category-pill-Pectoraux"]').trigger('click')
        await wrapper.get('[dusk="category-pill-all"]').trigger('click')

        expect(wrapper.emitted('update:categorie')).toEqual([['Pectoraux'], ['all']])
        wrapper.unmount()
    })

    it('amène au champ avec ⌘K comme avec Ctrl+K', async () => {
        const wrapper = monter()

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))
        expect(document.activeElement).toBe(wrapper.get('#search-exercises-input').element)

        wrapper.get('#search-exercises-input').element.blur()
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'K', ctrlKey: true }))
        expect(document.activeElement).toBe(wrapper.get('#search-exercises-input').element)
        wrapper.unmount()
    })

    it('vide la recherche avec Échap seulement quand le champ a le focus', async () => {
        const wrapper = monter({ recherche: 'trac' })
        const champ = wrapper.get('#search-exercises-input').element

        champ.blur()
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        expect(wrapper.emitted('update:recherche')).toBeUndefined()

        champ.focus()
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

        expect(wrapper.emitted('update:recherche')).toEqual([['']])
        expect(document.activeElement).not.toBe(champ)
        wrapper.unmount()
    })

    it('retire l’écouteur clavier qu’il avait posé en partant', () => {
        const pose = vi.spyOn(document, 'addEventListener')
        const wrapper = monter()
        const enregistrement = pose.mock.calls.find(([type]) => type === 'keydown')
        expect(enregistrement).toBeDefined()

        const retrait = vi.spyOn(document, 'removeEventListener')
        wrapper.unmount()

        expect(retrait).toHaveBeenCalledWith('keydown', enregistrement[1])
    })
})
