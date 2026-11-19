import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import ActiveWorkoutBanner from '@/Components/Dashboard/ActiveWorkoutBanner.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import { deposerLeRepos, effacerLeRepos, reposDuCompte } from '@/Utils/reposEnCours'

/*
 * Le repos qui continue ailleurs dans l'application (#2434) : le bandeau de la
 * séance en cours le dit à la place de la durée, sur l'horloge, l'annonce une
 * fois à sa fin, puis s'efface. Et le minuteur remonté repart de ce qu'il reste.
 */
const MAINTENANT = new Date('2026-11-19T18:00:00.000Z')

beforeAll(() => {
    globalThis.route = (nom, parametres) => `/${nom}/${parametres.workout}`
})

const depot = (surcharge = {}) => ({
    duree: 90,
    echeance: MAINTENANT.getTime() + 72_000,
    restantEnPause: null,
    adresse: '/workouts/12',
    intitule: 'Push A',
    compte: 7,
    ...surcharge,
})

const monter = (repos, compact = true) =>
    mount(ActiveWorkoutBanner, {
        props: {
            workout: { id: 12, name: 'Push A', started_at: new Date(MAINTENANT.getTime() - 600_000).toISOString() },
            compact,
            repos,
        },
        global: {
            directives: { press: {} },
            mocks: { route: (nom, parametres) => `/${nom}/${parametres.workout}` },
            stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } },
        },
    })

const duree = (wrapper) => wrapper.get('[dusk="active-workout-elapsed"]').text()

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(MAINTENANT)
    effacerLeRepos()
})

afterEach(() => {
    vi.useRealTimers()
    effacerLeRepos()
})

describe('le repos dans le bandeau de la séance', () => {
    it('garde la durée de la séance sans repos', async () => {
        const wrapper = monter(null)

        await wrapper.vm.$nextTick()

        expect(duree(wrapper)).toContain('10 min')
    })

    it.each([true, false])('dit le repos à la place de la durée, sur l’horloge (compact : %s)', async (compact) => {
        const wrapper = monter(depot(), compact)

        expect(duree(wrapper)).toContain('Repos 1:12')

        await vi.advanceTimersByTimeAsync(12_000)

        expect(duree(wrapper)).toContain('Repos 1:00')
    })

    it('dit un repos en pause, au reste figé', async () => {
        const wrapper = monter(depot({ echeance: null, restantEnPause: 45 }))

        await vi.advanceTimersByTimeAsync(20_000)

        expect(duree(wrapper)).toContain('Repos en pause 0:45')
    })

    it('n’annonce rien pendant le décompte, et « Repos terminé » une fois, à la fin', async () => {
        const repos = depot({ echeance: MAINTENANT.getTime() + 3_000 })

        deposerLeRepos(repos)

        const wrapper = monter(reposDuCompte(7))
        const annonce = () => wrapper.get('[role="status"]').text()

        expect(annonce()).toBe('')
        await vi.advanceTimersByTimeAsync(2_000)
        expect(annonce()).toBe('')
        await vi.advanceTimersByTimeAsync(1_000)
        expect(annonce()).toBe('Repos terminé')
        expect(duree(wrapper)).toContain('10 min')
    })

    it('efface le dépôt après l’annonce, et pas avant', async () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 1_000 }))

        monter(reposDuCompte(7))
        await vi.advanceTimersByTimeAsync(1_000)
        expect(reposDuCompte(7)).not.toBeNull()

        await vi.advanceTimersByTimeAsync(4_000)
        expect(reposDuCompte(7)).toBeNull()
    })

    it('n’efface pas un repos qui a remplacé celui qui finissait', async () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 1_000 }))

        const wrapper = monter(reposDuCompte(7))

        await vi.advanceTimersByTimeAsync(1_000)
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 600_000 }))
        await wrapper.setProps({ repos: reposDuCompte(7) })
        await vi.advanceTimersByTimeAsync(4_000)

        expect(reposDuCompte(7)).not.toBeNull()
    })
})

describe('le minuteur d’un repos repris', () => {
    const monterLeMinuteur = (props) =>
        mount(RestTimer, {
            props: { duration: 90, ...props },
            global: { stubs: { GlassToggle: true } },
        })

    it('repart de ce qu’il reste, sur la durée d’origine', () => {
        const wrapper = monterLeMinuteur({ secondesRestantes: 40 })

        expect(wrapper.get('[role="timer"]').text()).toBe('0:40')
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe(String((40 / 90) * 100))
    })

    it('attend le geste de reprise quand le repos était en pause, puis le dit', async () => {
        const wrapper = monterLeMinuteur({ secondesRestantes: 40, enPause: true })

        await vi.advanceTimersByTimeAsync(5_000)
        expect(wrapper.get('[role="timer"]').text()).toBe('0:40')

        await wrapper.get('button[aria-label="Démarrer le minuteur"]').trigger('click')
        expect(wrapper.emitted('reprise')).toEqual([[40]])

        await vi.advanceTimersByTimeAsync(3_000)
        expect(wrapper.get('[role="timer"]').text()).toBe('0:37')

        await wrapper.get('button[aria-label="Pause"]').trigger('click')
        expect(wrapper.emitted('pause')).toEqual([[37]])
    })
})
