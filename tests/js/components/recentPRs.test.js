import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import { nombre } from '@/Utils/nombre'
import RecentPRs from '@/Components/Dashboard/RecentPRs.vue'

const monter = (recentPRs) => mount(RecentPRs, { props: { recentPRs }, global: { directives: { press: {} } } })

/** Une ligne par record, dans l'ordre où l'accueil les a envoyés. */
const lignes = (wrapper) => wrapper.findAllComponents({ name: 'GlassCard' })

const record = (surcharge = {}) => ({
    id: 1,
    type: 'max_weight',
    value: 120,
    secondary_value: 5,
    achieved_at: '2026-11-12T10:00:00Z',
    exercise: { name: 'Développé couché' },
    ...surcharge,
})

describe('RecentPRs', () => {
    it('dit l’exercice, le jour et la charge × répétitions du poids max', () => {
        const wrapper = monter([record()])

        expect(lignes(wrapper)).toHaveLength(1)
        expect(lignes(wrapper)[0].text()).toContain('Développé couché')
        expect(lignes(wrapper)[0].text()).toContain('12 nov.')
        expect(lignes(wrapper)[0].text()).toContain('Poids max')
        expect(lignes(wrapper)[0].text()).toContain('120 kg × 5')
    })

    it('n’écrit « × » que pour le poids max, dont la valeur secondaire est des répétitions', () => {
        const wrapper = monter([
            record({ id: 1, type: 'max_1rm', value: 137.5, secondary_value: 120 }),
            record({ id: 2, type: 'max_weight', value: 100, secondary_value: null }),
        ])

        expect(lignes(wrapper)[0].text()).toContain('1RM estimé')
        expect(lignes(wrapper)[0].text()).toContain('137,5 kg')
        expect(lignes(wrapper)[0].text()).not.toContain('×')
        expect(lignes(wrapper)[1].text()).toContain('100 kg')
        expect(lignes(wrapper)[1].text()).not.toContain('×')
    })

    it('dit en kilos le volume d’une série, qui est un poids soulevé et non un compte', () => {
        const wrapper = monter([record({ id: 3, type: 'max_volume_set', value: 1200, exercise: { name: 'Rowing' } })])

        expect(lignes(wrapper)[0].text()).toContain('Volume')
        expect(lignes(wrapper)[0].text()).toContain(`${nombre(1200, 0)} kg`)
    })

    it('n’affiche que deux lignes, même si la page en reçoit plus', () => {
        const wrapper = monter([record({ id: 1 }), record({ id: 2 }), record({ id: 3 })])

        expect(lignes(wrapper)).toHaveLength(2)
    })

    it('ne dessine aucun graphique', () => {
        const wrapper = monter([record()])

        expect(wrapper.find('canvas').exists()).toBe(false)
        expect(wrapper.text()).toContain('Records récents')
    })

    it('disparaît entièrement plutôt que de montrer un podium vide', () => {
        const wrapper = monter([])

        expect(wrapper.find('section').exists()).toBe(false)
        expect(lignes(wrapper)).toHaveLength(0)
    })

    it('ne se soulève ni ne se comprime au toucher : la section n’ouvre rien (#2355)', () => {
        const classes = monter([record()]).find('section').classes()

        expect(classes.filter((classe) => /^(hover|active):/.test(classe))).toEqual([])
        expect(monter([record()]).find('section a, section button').exists()).toBe(false)
    })

    it('survit à un record dont l’exercice a été supprimé', () => {
        const wrapper = monter([record({ exercise: null, secondary_value: null, value: 60 })])

        expect(lignes(wrapper)).toHaveLength(1)
        expect(lignes(wrapper)[0].text()).toContain('60 kg')
    })
})
