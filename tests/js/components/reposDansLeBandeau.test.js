import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'

import ActiveWorkoutBanner from '@/Components/Dashboard/ActiveWorkoutBanner.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import { deposerLeRepos, effacerLeRepos, reposAMontrer, reposDuCompte } from '@/Utils/reposEnCours'

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

const monter = (repos) =>
    mount(ActiveWorkoutBanner, {
        props: {
            workout: { id: 12, name: 'Push A', started_at: new Date(MAINTENANT.getTime() - 600_000).toISOString() },
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

    it('dit le repos à la place de la durée, sur l’horloge', async () => {
        const wrapper = monter(depot())

        expect(duree(wrapper)).toContain('Repos 1:12')

        await vi.advanceTimersByTimeAsync(12_000)

        expect(duree(wrapper)).toContain('Repos 1:00')
    })

    it('dessine l’anneau du repos, rempli de ce qu’il reste sur sa durée, et pas sans repos', async () => {
        const wrapper = monter(depot())

        const trait = () => wrapper.get('[dusk="anneau-du-repos"] [data-testid="trait-de-l-anneau"]')

        expect(trait().attributes('style')).toContain('stroke-dashoffset: 20')

        await vi.advanceTimersByTimeAsync(12_000)

        expect(trait().attributes('style')).toContain('stroke-dashoffset: 33.3')
        expect(monter(null).find('[dusk="anneau-du-repos"]').exists()).toBe(false)
    })

    it('ne dessine aucun trait à zéro, pour ne laisser aucun point au bout de l’anneau', async () => {
        const wrapper = monter(depot({ echeance: MAINTENANT.getTime() + 1_000 }))

        await vi.advanceTimersByTimeAsync(1_000)

        expect(wrapper.find('[data-testid="trait-de-l-anneau"]').exists()).toBe(false)
    })

    it('dessine le trait au bout plat, qui ne laisse pas de pastille à la dernière seconde', () => {
        const wrapper = monter(depot({ echeance: MAINTENANT.getTime() + 1_000 }))

        expect(wrapper.get('[data-testid="trait-de-l-anneau"]').attributes('stroke-linecap')).toBeUndefined()
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
        expect(duree(wrapper)).toContain('Repos terminé')
    })

    /*
     * Le bandeau reçoit son repos du layout (`reposAMontrer`), réactif : l'annonce
     * ne doit pas le faire disparaître elle-même (le dépôt change quand elle part).
     */
    const monterAvecLeLayout = () =>
        mount(
            defineComponent({
                setup: () => {
                    const repos = computed(() => reposAMontrer(7, { id: 12 }))

                    return () =>
                        h(ActiveWorkoutBanner, {
                            workout: {
                                id: 12,
                                name: 'Push A',
                                started_at: new Date(MAINTENANT.getTime() - 600_000).toISOString(),
                            },
                            repos: repos.value,
                        })
                },
            }),
            {
                global: {
                    directives: { press: {} },
                    mocks: { route: (nom, parametres) => `/${nom}/${parametres.workout}` },
                    stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } },
                },
            },
        )

    it('garde « Repos terminé » à l’écran pendant toute l’annonce, puis efface le dépôt (avec le layout réel)', async () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 1_000, seance: 12 }))

        const wrapper = monterAvecLeLayout()
        const annonce = () => wrapper.get('[role="status"]').text()

        expect(duree(wrapper)).toContain('Repos 0:01')
        await vi.advanceTimersByTimeAsync(1_000)
        expect(annonce()).toBe('Repos terminé')

        await vi.advanceTimersByTimeAsync(3_000)
        expect(annonce()).toBe('Repos terminé')
        expect(reposDuCompte(7)).not.toBeNull()

        await vi.advanceTimersByTimeAsync(1_000)
        expect(reposDuCompte(7)).toBeNull()
        expect(annonce()).toBe('')
    })

    it('note l’annonce au départ du bandeau : celui de la page suivante ne redit pas « Repos terminé »', async () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 1_000, seance: 12 }))

        const wrapper = monterAvecLeLayout()

        await vi.advanceTimersByTimeAsync(1_000)
        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        wrapper.unmount()

        // La page change dans la seconde : le nouveau bandeau ne reçoit plus ce repos.
        expect(reposAMontrer(7, { id: 12 })).toBeNull()
        expect(reposDuCompte(7)).not.toBeNull()
    })

    it('ne marque pas comme annoncé un repos neuf arrivé après l’annonce', async () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() + 1_000, seance: 12 }))

        const wrapper = monterAvecLeLayout()

        await vi.advanceTimersByTimeAsync(5_000)
        deposerLeRepos(depot({ echeance: Date.now() + 600_000, seance: 12 }))
        wrapper.unmount()

        expect(reposAMontrer(7, { id: 12 })).not.toBeNull()
    })

    it('ne laisse pas un dépôt périmé annoncer « Repos terminé » à un bandeau qui n’est pas le sien', () => {
        deposerLeRepos(depot({ echeance: MAINTENANT.getTime() - 5_000, seance: 3 }))

        expect(reposAMontrer(7, { id: 12 })).toBeNull()
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
            global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
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

        await wrapper.get('button[aria-label="Reprendre le repos"]').trigger('click')
        expect(wrapper.emitted('reprise')).toEqual([[40]])

        await vi.advanceTimersByTimeAsync(3_000)
        expect(wrapper.get('[role="timer"]').text()).toBe('0:37')

        await wrapper.get('button[aria-label="Mettre le repos en pause"]').trigger('click')
        expect(wrapper.emitted('pause')).toEqual([[37]])
    })
})
