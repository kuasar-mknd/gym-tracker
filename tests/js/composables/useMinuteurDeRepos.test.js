import { describe, it, expect, vi, beforeEach } from 'vitest'

const inertia = vi.hoisted(() => ({ patch: vi.fn(), utilisateur: { auto_rest_timer: true, default_rest_time: 75 } }))
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: inertia.utilisateur } } }),
    router: { patch: inertia.patch },
}))

import { REPOS_AUTOMATIQUE_SANS_RESEAU, useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'
import { annoncesPendant } from '../utils/annoncesSansReseau'
import { noterQueLeServeurRepond, useEtatDuReseau } from '@/composables/useEtatDuReseau'

beforeEach(() => {
    vi.clearAllMocks()
    inertia.utilisateur.auto_rest_timer = true
    globalThis.route = (nom) => `/${nom}`
})

describe('le minuteur de repos', () => {
    it('démarre à la durée de l’exercice, sinon à celle du profil, et compte chaque repos', () => {
        const minuteur = useMinuteurDeRepos()
        expect(minuteur.showTimer.value).toBe(false)

        minuteur.apresValidation(120)
        expect(minuteur.showTimer.value).toBe(true)
        expect(minuteur.timerDuration.value).toBe(120)
        expect(minuteur.timerRun.value).toBe(1)

        minuteur.openRestTimer()
        expect(minuteur.timerDuration.value).toBe(75)
        expect(minuteur.timerRun.value).toBe(2)
    })

    it('écrit le réglage tout de suite et ne démarre plus rien après une validation quand il est coupé', () => {
        const minuteur = useMinuteurDeRepos()

        minuteur.setAutoRestTimer(false)
        minuteur.apresValidation(60)

        expect(minuteur.autoRestTimer.value).toBe(false)
        expect(inertia.patch).toHaveBeenCalledWith(
            '/profile.rest-timer.update',
            { auto_rest_timer: false },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        )
        expect(minuteur.showTimer.value).toBe(false)
    })

    /*
     * Sans réseau, l'interrupteur ne changeait qu'à l'écran : la séance
     * suivante repartait sur l'ancien réglage, sans que rien ne l'ait dit (#2048).
     */
    it('revient en arrière et le dit quand le réglage part sans réseau', () => {
        noterQueLeServeurRepond()
        const minuteur = useMinuteurDeRepos()

        minuteur.setAutoRestTimer(false)
        const options = inertia.patch.mock.calls[0][2]
        // Comme Inertia : l'échec du réseau, puis la fin de la visite.
        const { rendu, annonces } = annoncesPendant(() => options.onNetworkError({ code: 'ERR_NETWORK' }))
        options.onFinish()

        expect(rendu).toBe(false)
        expect(minuteur.autoRestTimer.value).toBe(true)
        expect(annonces).toEqual([REPOS_AUTOMATIQUE_SANS_RESEAU])
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    /*
     * Un 419, un 500, un refus ou une visite annulée laissaient l'interrupteur
     * changé à l'écran (#2077). Le vrai client joue chaque cas dans
     * `tests/js/pages/misesAJourOptimistesAvecInertia.test.js`.
     */
    it('revient au réglage de la page quand la visite finit sans succès, quelle qu’en soit la raison', () => {
        const minuteur = useMinuteurDeRepos()

        minuteur.setAutoRestTimer(false)
        inertia.patch.mock.calls[0][2].onFinish()

        expect(minuteur.autoRestTimer.value).toBe(true)
    })

    it('garde le réglage accepté, et ne laisse pas un envoi dépassé défaire le suivant', () => {
        const minuteur = useMinuteurDeRepos()

        minuteur.setAutoRestTimer(false)
        minuteur.setAutoRestTimer(true)
        minuteur.setAutoRestTimer(false)
        // Les deux premiers envois, interrompus par le suivant, finissent sans succès.
        inertia.patch.mock.calls[0][2].onFinish()
        inertia.patch.mock.calls[1][2].onFinish()
        expect(minuteur.autoRestTimer.value).toBe(false)

        inertia.patch.mock.calls[2][2].onSuccess()
        inertia.patch.mock.calls[2][2].onFinish()
        expect(minuteur.autoRestTimer.value).toBe(false)
    })

    it('lit le réglage du profil au départ', () => {
        inertia.utilisateur.auto_rest_timer = false

        expect(useMinuteurDeRepos().autoRestTimer.value).toBe(false)
    })
})
