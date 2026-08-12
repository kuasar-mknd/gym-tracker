import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: { href: { type: String, default: '' } }, template: '<a :href="href"><slot /></a>' },
}))

const ResponsiveNavLink = (await import('@/Components/Navigation/ResponsiveNavLink.vue')).default

const mountLink = (props) =>
    mount(ResponsiveNavLink, {
        props,
        slots: { default: 'Mes Séances' },
        global: { directives: { press: {} } },
    })

describe('ResponsiveNavLink', () => {
    it('navigates to the destination it was given, with its label', () => {
        const wrapper = mountLink({ href: '/workouts' })

        expect(wrapper.get('a').attributes('href')).toBe('/workouts')
        expect(wrapper.text()).toBe('Mes Séances')
    })

    it('marks the current entry so the burger menu says where you are', () => {
        const current = mountLink({ href: '/workouts', active: true })
        const other = mountLink({ href: '/goals', active: false })

        expect(current.get('a').classes()).toContain('text-electric-orange')
        expect(other.get('a').classes()).not.toContain('text-electric-orange')
    })

    it('treats a link with no active flag as not current', () => {
        // Every call site passes :active="route().current(...)", which is false
        // for all but one entry; a default that read as active would light the
        // whole menu up.
        const wrapper = mountLink({ href: '/goals' })

        expect(wrapper.get('a').classes()).not.toContain('text-electric-orange')
        expect(wrapper.get('a').classes()).toContain('text-text-muted')
    })
})
