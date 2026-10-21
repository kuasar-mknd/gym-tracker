import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { h } from 'vue'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    naviguerDansLHistorique,
    ouvrirLaListeDesSeances,
    present,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page n'a rien à montrer : la séance et sa question de fin, la vraie, comptent seules.
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_props, { slots }) =>
                () =>
                    rendre('div', [slots['header-actions']?.(), slots.default?.()]),
        },
    }
})
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))

/*
 * « Terminer » en ligne (#2049), avec le vrai client d'Inertia, la vraie page
 * de séance, sa vraie question de fin et la vraie file hors ligne.
 *
 * La fin passe par l'API (`PATCH /api/v1/workouts/finish`) : une seule
 * requête, puis la visite de l'accueil. L'invitation aux notifications vient
 * de la réponse (`meta.proposer_les_notifications`), que la page pose en
 * donnée flash à l'arrivée (`router.flash`) : l'API n'en pose aucune dans la
 * session, qu'un rejeu de la file ferait surgir sur une page au hasard, et
 * Inertia ne la range pas dans l'historique : Retour puis Avance ne la
 * remontrent pas.
 *
 * L'accueil se réduit à ce qu'en lit l'invitation, la même condition que
 * `Pages/Dashboard.vue` : `$page.flash.proposerLesNotifications === true`.
 */

const pages = suivreLesPagesMontees()

let visites
let visiter
let usePage

const Accueil = {
    name: 'Accueil',
    setup() {
        const page = usePage()

        return () =>
            h('div', { id: 'accueil' }, [
                page.flash?.proposerLesNotifications === true
                    ? h('p', { id: 'invitation-aux-notifications' }, 'Activer les notifications')
                    : null,
            ])
    },
}

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    reseau.serveur.proposerLesNotifications = true

    ;({ visites, visiter } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: (inertia) => {
            usePage = inertia.usePage
        },
        composants: { Dashboard: Accueil },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    vi.useRealTimers()
    await fermerLaListeDesSeances()
})

it('termine par une seule requête, mène à l’accueil, et l’invitation aux notifications ne revient pas par l’historique', async () => {
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    visites.length = 0

    await cliquer('[dusk="finish-workout-mobile"]')
    await vi.waitUntil(() => present('dialog[open] [dusk="confirm-finish-button"]'))
    // L'heure d'un appareil en retard de trois heures, figée autour du seul geste.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-19T06:42:00.000Z'))
    await cliquer('[dusk="confirm-finish-button"]')
    await vi.waitUntil(() => reseau.serveur.requetes.length > 0)
    vi.useRealTimers()
    await vi.waitUntil(() => present('#accueil'))
    await flushPromises()

    // Une seule requête, la fin, et aucune visite qui écrive : l'accueil seul.
    expect(reseau.serveur.resume()).toEqual([
        'patch /api/v1/workouts/finish {"workout_id":5,"ended_at":"2026-10-19T06:42:00.000Z"}',
    ])
    /*
     * L'heure de l'appareil au départ de la fin, lue à l'envoi, à côté de
     * celle du geste (#2049, relu le 2026-10-21) : le serveur n'en garde que
     * l'écart, ici le temps que la page attende ses saisies (`vi.waitUntil`
     * avance l'horloge figée), et termine la séance à son heure à lui. Lue
     * seule, l'heure d'un appareil en retard ramenait la fin au début de la
     * séance.
     */
    const { 'X-Heure-De-L-Appareil': auDepart, ...autresEntetes } = reseau.serveur.requetes[0].entetes
    const ageDuGeste = Date.parse(auDepart) - Date.parse('2026-10-19T06:42:00.000Z')

    expect(autresEntetes).toEqual({})
    expect(auDepart).toMatch(/^2026-10-19T06:42:\d{2}\.\d{3}Z$/)
    expect(ageDuGeste).toBeGreaterThanOrEqual(0)
    expect(ageDuGeste).toBeLessThan(1000)
    expect(reseau.serveur.fins.has(5)).toBe(true)
    expect(visites).toEqual(['/dashboard'])
    expect(fileDurable()).toEqual([])

    // La réponse dit d'inviter : l'accueil le montre, posé par la page à l'arrivée.
    await vi.waitUntil(() => present('#invitation-aux-notifications'))
    expect(usePage().flash).toEqual({ proposerLesNotifications: true })

    // Retour puis Avance : l'historique rend l'accueil sans sa donnée flash.
    await naviguerDansLHistorique('back', '[dusk="exercise-card-0"]')
    await naviguerDansLHistorique('forward', '#accueil')

    expect(present('#invitation-aux-notifications')).toBe(false)
    expect(visites).toEqual(['/dashboard'])

    await historiqueAuRepos()
})
