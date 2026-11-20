import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount } from '@vue/test-utils'

const inertia = vi.hoisted(() => ({ utilisateur: { id: 7, auto_rest_timer: true, default_rest_time: 90 } }))
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: inertia.utilisateur } } }),
    router: { patch: vi.fn() },
}))

import { memoireDeLaPage, useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'
import { useSortieAudio } from '@/composables/useSortieAudio'
import { effacerLeRepos, reposDuCompte } from '@/Utils/reposEnCours'

/*
 * Le repos survit à la page (#2434) : elle dépose son échéance en partant pour
 * une autre, et la reprend à son retour, sur l'horloge. Ces cas montent le
 * composable dans une vraie page, qu'on démonte comme le fait une navigation.
 */
const MAINTENANT = new Date('2026-11-19T18:00:00.000Z')

/** @param {{ close?: boolean, sansMemoire?: boolean }} etat si la séance est déjà close, ou le minuteur sans mémoire */
const monterLaPage = (etat = {}) => {
    const page = { url: '/workouts/5', props: { auth: { user: inertia.utilisateur } } }
    let minuteur
    const estClose = ref(etat.close ?? false)

    const wrapper = mount(
        defineComponent({
            setup() {
                minuteur = useMinuteurDeRepos({
                    sortieAudio: useSortieAudio(),
                    memoire: etat.sansMemoire
                        ? undefined
                        : memoireDeLaPage({ page, localWorkout: ref({ id: 5, name: 'Pousser' }), estClose }),
                })

                return () => h('div')
            },
        }),
    )

    return { wrapper, minuteur: () => minuteur, page, estClose }
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(MAINTENANT)
    effacerLeRepos()
})

afterEach(() => {
    vi.useRealTimers()
    effacerLeRepos()
})

describe('un repos qui quitte la page avec elle', () => {
    it('dépose son échéance, pas ce qu’il reste, quand la page part pour une autre', () => {
        const { wrapper, minuteur, page } = monterLaPage()

        minuteur().openRestTimer()
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 20_000))
        page.url = '/dashboard'
        wrapper.unmount()

        expect(reposDuCompte(7)).toMatchObject({
            duree: 90,
            echeance: MAINTENANT.getTime() + 90_000,
            restantEnPause: null,
            adresse: '/workouts/5',
            seance: 5,
            intitule: 'Pousser',
        })
    })

    it('dépose le reste d’un repos en pause, et pas d’échéance', () => {
        const { wrapper, minuteur, page } = monterLaPage()

        minuteur().openRestTimer()
        minuteur().liaisonDuMinuteur.value.onPause(61)
        page.url = '/dashboard'
        wrapper.unmount()

        expect(reposDuCompte(7)).toMatchObject({ echeance: null, restantEnPause: 61 })
    })

    it('reprend l’échéance à l’endroit où l’horloge l’a menée, en route', () => {
        const premiere = monterLaPage()

        premiere.minuteur().openRestTimer()
        premiere.page.url = '/dashboard'
        premiere.wrapper.unmount()
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 25_000))

        const seconde = monterLaPage()
        const liaison = seconde.minuteur().liaisonDuMinuteur.value

        expect(seconde.minuteur().showTimer.value).toBe(true)
        expect(liaison).toMatchObject({ duration: 90, secondesRestantes: 65, enPause: false })
        expect(reposDuCompte(7)).toBeNull()
    })

    it('reprend un repos en pause en pause, avec son reste', () => {
        const premiere = monterLaPage()

        premiere.minuteur().openRestTimer()
        premiere.minuteur().liaisonDuMinuteur.value.onPause(40)
        premiere.page.url = '/dashboard'
        premiere.wrapper.unmount()
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 600_000))

        const seconde = monterLaPage()

        expect(seconde.minuteur().liaisonDuMinuteur.value).toMatchObject({ secondesRestantes: 40, enPause: true })
    })

    it('repart d’une nouvelle échéance quand la pause reprend', () => {
        const premiere = monterLaPage()

        premiere.minuteur().openRestTimer()
        premiere.minuteur().liaisonDuMinuteur.value.onPause(40)
        premiere.minuteur().liaisonDuMinuteur.value.onReprise(40)
        premiere.page.url = '/dashboard'
        premiere.wrapper.unmount()

        expect(reposDuCompte(7)).toMatchObject({ echeance: MAINTENANT.getTime() + 40_000, restantEnPause: null })
    })

    it('garde l’échéance exacte à travers les allers-retours : le repos ne s’allonge pas', () => {
        const premiere = monterLaPage()

        premiere.minuteur().openRestTimer()
        const echeance = MAINTENANT.getTime() + 90_000
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 20_300))
        premiere.page.url = '/dashboard'
        premiere.wrapper.unmount()

        for (let allerRetour = 0; allerRetour < 3; allerRetour++) {
            const page = monterLaPage()

            page.page.url = '/dashboard'
            page.wrapper.unmount()
        }

        expect(reposDuCompte(7).echeance).toBe(echeance)
    })

    it('ne dépose pas un repos qui a fini avant le départ de la page', () => {
        const { wrapper, minuteur, page } = monterLaPage()

        minuteur().openRestTimer()
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 91_000))
        page.url = '/dashboard'
        wrapper.unmount()

        expect(reposDuCompte(7)).toBeNull()
    })

    it('ne reprend pas un repos que l’horloge a fini', () => {
        const premiere = monterLaPage()

        premiere.minuteur().openRestTimer()
        premiere.page.url = '/dashboard'
        premiere.wrapper.unmount()
        vi.setSystemTime(new Date(MAINTENANT.getTime() + 91_000))

        expect(monterLaPage().minuteur().showTimer.value).toBe(false)
    })

    it('ne dépose rien sans repos ouvert, une fois le repos fermé, ni sans départ de la page', () => {
        const sansRepos = monterLaPage()

        sansRepos.page.url = '/dashboard'
        sansRepos.wrapper.unmount()
        expect(reposDuCompte(7)).toBeNull()

        const ferme = monterLaPage()

        ferme.minuteur().openRestTimer()
        ferme.minuteur().showTimer.value = false
        ferme.page.url = '/dashboard'
        ferme.wrapper.unmount()
        expect(reposDuCompte(7)).toBeNull()

        const surPlace = monterLaPage()

        surPlace.minuteur().openRestTimer()
        surPlace.wrapper.unmount()
        expect(reposDuCompte(7)).toBeNull()
    })

    it('ne laisse rien d’une séance terminée', () => {
        const { wrapper, minuteur, page, estClose } = monterLaPage()

        minuteur().openRestTimer()
        estClose.value = true
        page.url = '/dashboard'
        wrapper.unmount()

        expect(reposDuCompte(7)).toBeNull()
    })

    it('ferme la carte du repos quand la séance se termine sur la page (le bilan reste à l’écran)', async () => {
        const { minuteur, estClose } = monterLaPage()

        minuteur().openRestTimer()
        expect(minuteur().showTimer.value).toBe(true)

        estClose.value = true
        await nextTick()

        expect(minuteur().showTimer.value).toBe(false)
    })

    it('ne touche à rien sans mémoire : le minuteur d’avant', () => {
        const { wrapper, minuteur } = monterLaPage({ sansMemoire: true })

        minuteur().openRestTimer()
        wrapper.unmount()

        expect(reposDuCompte(7)).toBeNull()
    })
})
