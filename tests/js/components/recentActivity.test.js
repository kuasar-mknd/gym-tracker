import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

import { CIBLE, hauteurDuRendu } from '../conventions/ciblesTactiles'
import { poserLeFuseau } from '../utils/fuseauDeLAppareil'

/**
 * Every line this component prints about a session is rendered in the reader's
 * own zone: `new Date(started_at)` parses an instant, and the `toLocale…` call
 * then places it locally. Asserted from UTC that is invisible — the formatted day
 * and the UTC day agree, and a component that had dropped the zone entirely
 * would pass. One of the fixtures below is a late-evening session that falls on
 * the *previous* calendar day once placed in New York, which is exactly the case
 * a zone-blind rendering gets wrong.
 */
beforeAll(() => poserLeFuseau('America/New_York', 240, new Date('2026-08-03T01:15:00Z')))

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: { href: { type: String, default: '' } },
        template: '<a :href="href"><slot /></a>',
    },
}))

import { nombre } from '@/Utils/nombre'
import RecentActivity from '@/Components/Dashboard/RecentActivity.vue'

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${JSON.stringify(params)}`)
})

const session = (overrides = {}) => ({
    id: 1,
    name: 'Push',
    started_at: '2026-02-10T14:30:00Z',
    ended_at: '2026-02-10T15:15:00Z',
    workout_volume: 4200,
    workout_lines_count: 5,
    ...overrides,
})

const mountActivity = (recentWorkouts) =>
    mount(RecentActivity, {
        props: { recentWorkouts },
        global: { directives: { press: {} }, mocks: { route: globalThis.route } },
    })

/** The one line standing for a session, as the reader sees it. */
const cardFor = (wrapper, index = 0) => wrapper.findAll('a[href^="/workouts.show"]')[index]

describe('« Voir tout »', () => {
    it('offre au doigt une zone de 44 px de haut', () => {
        const lien = mountActivity([session()]).get('a[href="/workouts.index"]')

        expect(hauteurDuRendu(lien.element)).toBeGreaterThanOrEqual(CIBLE)
    })
})

describe('quand rien n’a encore été fait', () => {
    it('ne montre rien : l’accueil dit « Pas encore de séance » une seule fois, à sa place', () => {
        const wrapper = mountActivity([])

        expect(wrapper.find('section').exists()).toBe(false)
        expect(wrapper.text()).toBe('')
        expect(wrapper.find('button').exists()).toBe(false)
    })
})

describe('« Dernières séances »', () => {
    it('donne une ligne à chacune, vers sa propre page', () => {
        const wrapper = mountActivity([session({ id: 7, name: 'Push' }), session({ id: 9, name: 'Pull' })])

        expect(wrapper.text()).toContain('Dernières séances')
        // Paired name to href: two lines both linking to the same session would
        // survive any assertion made on the set of hrefs alone.
        expect(
            wrapper
                .findAll('a[href^="/workouts.show"]')
                .map((card) => [card.find('h4').text(), card.attributes('href')]),
        ).toEqual([
            ['Push', '/workouts.show/{"workout":7}'],
            ['Pull', '/workouts.show/{"workout":9}'],
        ])
    })

    it('n’affiche que trois séances, même si la page en reçoit plus', () => {
        const wrapper = mountActivity([1, 2, 3, 4].map((id) => session({ id })))

        expect(wrapper.findAll('a[href^="/workouts.show"]')).toHaveLength(3)
    })

    it('ne trace aucune frise des durées', () => {
        const wrapper = mountActivity([session()])

        expect(wrapper.find('canvas').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Durée des séances')
    })

    it('dit le jour, la durée et le volume de la séance', () => {
        const ligne = cardFor(mountActivity([session()]))

        expect(ligne.text()).toContain('mardi 10 févr.')
        expect(ligne.text()).toContain('45 min')
        expect(ligne.text()).toContain(`${nombre(4200, 0)} kg`)
    })

    it('date la séance dans le fuseau du lecteur', () => {
        const wrapper = mountActivity([
            session({ started_at: '2026-08-03T01:15:00Z', ended_at: '2026-08-03T02:00:00Z' }),
        ])

        // 01:15 UTC on Monday 3 August is 21:15 on Sunday 2 August in New York.
        // A rendering that dropped the zone would print "lundi 3 août" here.
        expect(cardFor(wrapper).text()).toContain('dimanche 2 août')
    })

    it('dit « En cours » plutôt qu’une durée pour une séance ouverte', () => {
        const ouverte = mountActivity([session({ ended_at: null })])
        const close = mountActivity([session()])

        expect(ouverte.text()).toContain('En cours')
        expect(ouverte.text()).not.toContain('min')
        expect(close.text()).not.toContain('En cours')
        expect(close.text()).toContain('45 min')
    })

    it('ne dit pas de volume nul pour une séance sans charge', () => {
        const ligne = cardFor(mountActivity([session({ workout_volume: 0 })]))

        expect(ligne.text()).not.toContain(' kg')
    })

    it('remplace un intitulé manquant par un libellé plutôt que par du vide', () => {
        expect(cardFor(mountActivity([session({ name: null })])).text()).toContain('Séance')
    })
})
