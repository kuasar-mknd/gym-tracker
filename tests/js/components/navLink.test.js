import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import NavLink from '@/Components/Navigation/NavLink.vue'

const monter = (props) =>
    mount(NavLink, {
        props: { href: '/stats', ...props },
        slots: { default: 'Stats' },
        global: {
            directives: { press: {} },
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
        },
    })

/**
 * Le lien de la page courante le dit aux lecteurs d'écran : la bulle de verre
 * qui le distingue à l'œil ne se lit pas.
 */
describe('NavLink', () => {
    it('annonce la page courante par aria-current', () => {
        expect(monter({ active: true }).attributes('aria-current')).toBe('page')
    })

    it('ne pose aucun aria-current sur un lien inactif', () => {
        expect(monter({ active: false }).attributes('aria-current')).toBeUndefined()
        expect(monter({}).attributes('aria-current')).toBeUndefined()
    })

    it('distingue le lien actif par une bulle de verre épais, sans capitales', () => {
        const actif = monter({ active: true }).classes()
        const inactif = monter({ active: false }).classes()

        expect(actif).toContain('verre-epais')
        expect(inactif).not.toContain('verre-epais')
        expect([...actif, ...inactif]).not.toContain('uppercase')
    })
})
