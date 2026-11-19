import { describe, it, expect, beforeAll, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import ToolsIndex from '@/Pages/Tools/Index.vue'

beforeAll(() => {
    globalThis.route = (nom) => `/${nom}`
})

describe('Tools/Index', () => {
    it('donne à chaque outil son propre pictogramme, le minuteur d’intervalles et le jeûne compris (#2355)', () => {
        const wrapper = mount(ToolsIndex, {
            global: {
                mocks: { route: globalThis.route },
                directives: { press: {} },
                stubs: {
                    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
                    GlassCard: { template: '<div><slot /></div>' },
                    GlassIcon: { props: ['name'], template: '<i :data-icone="name" />' },
                },
            },
        })

        const icones = wrapper.findAll('[data-icone]').map((icone) => icone.attributes('data-icone'))

        expect(icones.length).toBeGreaterThan(4)
        expect(icones.filter((nom, rang) => icones.indexOf(nom) !== rang)).toEqual([])
    })
})
