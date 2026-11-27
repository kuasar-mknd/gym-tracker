import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import WeeklyVolumeSection from '@/Components/Dashboard/WeeklyVolumeSection.vue'
import { nombre } from '@/Utils/nombre'

/** Les sept jours de la semaine du lundi 23 au dimanche 29 novembre 2026, au volume donné. */
const semaine = (volumes = [0, 0, 0, 0, 0, 0, 0]) =>
    volumes.map((volume, index) => ({
        date: `2026-11-${String(23 + index)}`,
        day_label: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'][index],
        volume,
    }))

const monter = (props = {}) =>
    mount(WeeklyVolumeSection, {
        props: { weeklyVolumeStats: {}, weeklyVolumeTrend: semaine(), ...props },
        global: { mocks: { route: (nom) => `/${nom}` } },
    })

const volumeDe = (wrapper) => wrapper.get('[dusk="dashboard-semaine-volume"]').text()
const resumeDe = (wrapper) => wrapper.get('[dusk="dashboard-semaine-resume"]').text()
const ecartDe = (wrapper) => wrapper.find('[dusk="dashboard-semaine-ecart"]')

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 10, 25, 10, 0, 0))
})

afterEach(() => {
    vi.useRealTimers()
})

describe('WeeklyVolumeSection', () => {
    it('sépare les milliers du volume de la semaine, en fr-CH', () => {
        const wrapper = monter({ weeklyVolumeStats: { current_week_volume: 21500, sessions_count: 3, percentage: 0 } })

        expect(volumeDe(wrapper)).toBe(`${nombre(21500, 0)} kg`)
    })

    it('retombe sur zéro quand la semaine n’a pas encore de volume', () => {
        expect(volumeDe(monter())).toBe('0 kg')
    })

    it('compte les séances de la semaine, accordées', () => {
        const une = monter({ weeklyVolumeStats: { current_week_volume: 900, sessions_count: 1, percentage: null } })
        const trois = monter({ weeklyVolumeStats: { current_week_volume: 900, sessions_count: 3, percentage: null } })

        expect(resumeDe(une)).toBe('1 séance')
        expect(resumeDe(trois)).toBe('3 séances')
    })

    it('dit une hausse, signée, « à ce jour »', () => {
        const wrapper = monter({ weeklyVolumeStats: { current_week_volume: 21500, sessions_count: 3, percentage: 12 } })

        expect(resumeDe(wrapper)).toBe('3 séances · +12 % à ce jour')
        expect(ecartDe(wrapper).classes()).toContain('text-trend-up')
    })

    it('dit une baisse sans y mettre un second signe', () => {
        const wrapper = monter({ weeklyVolumeStats: { current_week_volume: 9000, sessions_count: 2, percentage: -8 } })

        expect(resumeDe(wrapper)).toBe('2 séances · -8 % à ce jour')
        expect(resumeDe(wrapper)).not.toContain('+')
        expect(ecartDe(wrapper).classes()).toContain('text-trend-down')
    })

    it('dit « Stable » quand les deux semaines se valent à la même date', () => {
        const wrapper = monter({ weeklyVolumeStats: { current_week_volume: 21500, sessions_count: 3, percentage: 0 } })

        expect(resumeDe(wrapper)).toBe('3 séances · Stable à ce jour')
    })

    it('masque l’écart quand la semaine en cours n’a aucune séance, même si le serveur en envoie un', () => {
        const wrapper = monter({
            weeklyVolumeStats: { current_week_volume: 0, sessions_count: 0, percentage: -100 },
        })

        expect(ecartDe(wrapper).exists()).toBe(false)
        expect(wrapper.text()).not.toContain('-100')
        expect(wrapper.text()).not.toContain('à ce jour')
        expect(resumeDe(wrapper)).toBe('0 séance')
    })

    it('masque l’écart quand il n’y a rien à comparer sur la même durée de la semaine passée', () => {
        const wrapper = monter({
            weeklyVolumeStats: { current_week_volume: 21500, sessions_count: 3, percentage: null },
        })

        expect(ecartDe(wrapper).exists()).toBe(false)
        expect(volumeDe(wrapper)).toBe(`${nombre(21500, 0)} kg`)
    })

    it('masque l’écart quand la clé manque tout court', () => {
        const wrapper = monter({ weeklyVolumeStats: { current_week_volume: 21500, sessions_count: 3 } })

        expect(ecartDe(wrapper).exists()).toBe(false)
        expect(wrapper.text()).not.toContain('%')
    })

    it('trace sept barres, L M M J V S D, et non une courbe', () => {
        const wrapper = monter({ weeklyVolumeTrend: semaine([1200, 0, 900, 0, 0, 0, 0]) })
        const barres = wrapper.findAll('[data-volume]')

        expect(barres).toHaveLength(7)
        expect(wrapper.findAll('[data-jour]').map((jour) => jour.text())).toEqual(['L', 'M', 'M', 'J', 'V', 'S', 'D'])
        expect(wrapper.find('canvas').exists()).toBe(false)
    })

    it('donne à chaque barre une hauteur proportionnelle au volume le plus haut, et un filet au jour sans séance', () => {
        const wrapper = monter({ weeklyVolumeTrend: semaine([1200, 0, 600, 0, 0, 0, 0]) })
        const hauteurs = wrapper.findAll('[data-volume]').map((barre) => barre.element.style.height)

        expect(hauteurs[0]).toBe('100%')
        expect(hauteurs[2]).toBe('50%')
        expect(hauteurs[1]).toBe('4px')
    })

    it('marque le jour courant, et lui seul', () => {
        const wrapper = monter({ weeklyVolumeTrend: semaine([1200, 0, 600, 0, 0, 0, 0]) })
        const jours = wrapper.findAll('[data-jour]')

        expect(jours.map((jour) => jour.attributes('data-aujourdhui'))).toEqual([
            undefined,
            undefined,
            'oui',
            undefined,
            undefined,
            undefined,
            undefined,
        ])
        expect(jours[2].classes()).toContain('font-semibold')
    })

    it('se lit en entier au lecteur d’écran, jour par jour', () => {
        const wrapper = monter({ weeklyVolumeTrend: semaine([1200, 0, 600, 0, 0, 0, 0]) })

        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe(
            `Volume de chaque jour de la semaine : lundi ${nombre(1200, 0)} kg, mardi 0 kg, mercredi 600 kg, jeudi 0 kg, vendredi 0 kg, samedi 0 kg, dimanche 0 kg`,
        )
    })

    it('ne trace rien tant que la tendance manque', () => {
        const wrapper = monter({ weeklyVolumeTrend: [] })

        expect(wrapper.findAll('[data-volume]')).toHaveLength(0)
        expect(wrapper.find('[role="img"]').exists()).toBe(false)
    })
})
