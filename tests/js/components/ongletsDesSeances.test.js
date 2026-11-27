import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'

import { elementsDuGabarit, gabarit } from '../conventions/gabarits'
import { jsRoot } from '../conventions/sourceFiles'

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import OngletsDesSeances from '@/Components/Workout/OngletsDesSeances.vue'

const route = (nom) => `/resolved/${nom}`
globalThis.route = route

const monter = (actif, slots = {}) =>
    mount(OngletsDesSeances, {
        props: { actif },
        slots,
        global: {
            directives: { press: {} },
            mocks: { route, $page: { props: { auth: { user: { unread_notifications_count: 0 } } } } },
        },
    })

const ROUTES = {
    historique: '/resolved/workouts.index',
    modeles: '/resolved/templates.index',
    calendrier: '/resolved/calendar.index',
}
const NOMS = { historique: 'Historique', modeles: 'Modèles', calendrier: 'Calendrier' }

describe('OngletsDesSeances', () => {
    it.each(Object.keys(NOMS))('%s : l’onglet actif est un span aria-current, les deux autres des liens', (actif) => {
        const nav = monter(actif).get('nav[aria-label="Séances"]')
        const courant = nav.findAll('[aria-current="page"]')

        expect(courant).toHaveLength(1)
        expect(courant[0].element.tagName).toBe('SPAN')
        expect(courant[0].text()).toBe(NOMS[actif])

        const autres = Object.keys(NOMS).filter((cle) => cle !== actif)

        expect(nav.findAll('a').map((lien) => [lien.text(), lien.attributes('href')])).toEqual(
            autres.map((cle) => [NOMS[cle], ROUTES[cle]]),
        )
    })

    it('garde l’ordre Historique, Modèles, Calendrier', () => {
        expect(
            monter('modeles')
                .get('nav')
                .findAll('a, span')
                .map((element) => element.text()),
        ).toEqual(['Historique', 'Modèles', 'Calendrier'])
    })

    it('porte la cloche une seule fois, et se cache à partir du bureau', () => {
        const wrapper = monter('calendrier')

        expect(wrapper.findAll('a[href="/resolved/notifications.index"]')).toHaveLength(1)
        expect(wrapper.classes()).toContain('bureau:hidden')
    })

    it('pose la commande de la page sous la rangée', () => {
        const wrapper = monter('modeles', { default: '<button data-commande>Nouveau modèle</button>' })

        expect(wrapper.get('[data-commande]').element.previousElementSibling).toBe(
            wrapper.get('nav').element.parentElement,
        )
    })
})

/**
 * Dusk force le mouvement réduit : une rangée posée dans un bloc animé y
 * resterait immobile et passerait la mesure de `OngletsDesSeancesTest`, alors
 * qu'à l'écran elle glisserait à l'arrivée de chaque page. La garde se lit donc
 * dans les gabarits : aucun ancêtre de la rangée ne porte `animate-*` ni `stagger-*`.
 */
describe('la rangée dans les pages', () => {
    it.each(['Workouts/Index', 'Workouts/Templates/Index', 'Calendar/Index'])(
        '%s la pose hors de tout bloc animé',
        (page) => {
            const { elements } = elementsDuGabarit(gabarit(readFileSync(`${jsRoot}/Pages/${page}.vue`, 'utf8')))
            const rangee = elements.filter(({ nom }) => nom === 'OngletsDesSeances')

            expect(rangee).toHaveLength(1)

            for (let ancetre = rangee[0].parent; ancetre !== null; ancetre = ancetre.parent) {
                expect(ancetre.liste, `${ancetre.nom} anime la rangée`).not.toMatch(/(?:^|\s)(?:animate-|stagger-)/)
            }
        },
    )
})
