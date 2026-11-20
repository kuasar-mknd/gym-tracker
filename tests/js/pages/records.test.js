import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import { layoutStub } from './pageStubs'

const Records = (await import('@/Pages/Records/Index.vue')).default

beforeAll(() => {
    globalThis.route = (name, params) => (name === 'exercises.show' ? `/exercises/${params.exercise}` : `/${name}`)
})

const exercice = (surcharge = {}) => ({
    exercice_id: 7,
    nom: 'Squat',
    poids_max: { valeur: 140, repetitions: 3, date: '2026-11-10' },
    un_rm: { valeur: 154, date: '2026-11-02' },
    volume_serie: { valeur: 420, date: '2026-10-28' },
    ...surcharge,
})

const monter = (props = {}) =>
    mount(Records, {
        props: { records: [], tronque: false, ...props },
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: layoutStub },
        },
    })

describe('Records/Index', () => {
    it('dit les trois records d’un exercice, chacun avec son jour', () => {
        const wrapper = monter({ records: [exercice()] })
        const texte = wrapper.text()

        expect(texte).toContain('Squat')
        expect(texte).toContain('Charge max')
        expect(texte).toMatch(/140\s*kg\s*×\s*3/)
        expect(texte).toContain('1RM estimé')
        expect(texte).toMatch(/154\s*kg/)
        expect(texte).toContain('Meilleur volume')
        expect(texte).toMatch(/420\s*kg/)
        expect(texte).toContain('10 nov.')
        expect(texte).toContain('2 nov.')
        expect(texte).toContain('28 oct.')
    })

    it('mène à la fiche de chaque exercice', () => {
        const wrapper = monter({ records: [exercice(), exercice({ exercice_id: 9, nom: 'Tractions' })] })

        expect(wrapper.findAll('a').map((lien) => lien.attributes('href'))).toEqual(['/exercises/7', '/exercises/9'])
    })

    it('garde l’ordre que le serveur donne, le plus récent d’abord', () => {
        const wrapper = monter({
            records: [exercice({ exercice_id: 1, nom: 'Tractions' }), exercice({ exercice_id: 2, nom: 'Squat' })],
        })
        const noms = wrapper.findAll('[data-record] a').map((lien) => lien.text())

        expect(noms).toEqual(['Tractions', 'Squat'])
    })

    it('n’écrit pas de ligne pour un record qu’aucune série n’a établi', () => {
        const wrapper = monter({ records: [exercice({ un_rm: null, volume_serie: null })] })

        expect(wrapper.text()).toContain('Charge max')
        expect(wrapper.text()).not.toContain('1RM estimé')
        expect(wrapper.text()).not.toContain('Meilleur volume')
    })

    it('n’écrit pas de répétitions quand la série n’en a pas gardé', () => {
        const wrapper = monter({
            records: [exercice({ poids_max: { valeur: 60, repetitions: 0, date: '2026-11-10' } })],
        })

        expect(wrapper.text()).toMatch(/60\s*kg/)
        expect(wrapper.text()).not.toContain('×')
    })

    it('ne montre pas de jour qui manque', () => {
        const wrapper = monter({ records: [exercice({ un_rm: { valeur: 154, date: null } })] })
        const ligne = wrapper.findAll('dt').find((dt) => dt.text().includes('1RM estimé'))

        expect(ligne.text()).toBe('1RM estimé')
    })

    it('porte un état vide soigné, sans liste, quand il n’y a aucun record', () => {
        const wrapper = monter()

        expect(wrapper.text()).toContain('Pas encore de record')
        expect(wrapper.text()).toContain('Ils apparaissent après ta première série terminée.')
        expect(wrapper.findAll('[data-record]')).toHaveLength(0)
        expect(wrapper.findAll('a')).toHaveLength(0)
    })

    it('dit que la liste est bornée seulement quand elle l’est', () => {
        expect(monter({ records: [exercice()], tronque: true }).text()).toContain(
            'Seuls les exercices au record le plus récent sont listés.',
        )
        expect(monter({ records: [exercice()], tronque: false }).text()).not.toContain('Seuls les exercices')
    })

    it('ne pose pas de titre de niveau 1 de plus que celui de l’en-tête', () => {
        const wrapper = monter({ records: [exercice()] })

        expect(wrapper.findAll('h1')).toHaveLength(1)
        expect(wrapper.get('h1').text()).toBe('Records')
    })

    it('tutoie, sans point d’exclamation ni majuscules étirées', () => {
        const texte = monter().text()

        expect(texte).not.toContain('!')
        expect(texte).not.toMatch(/\b[A-ZÉ]{4,}\b/)
    })
})
