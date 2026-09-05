import { describe, it, expect, vi, beforeEach } from 'vitest'

const inertia = vi.hoisted(() => ({ patch: vi.fn(), utilisateur: { auto_rest_timer: true, default_rest_time: 75 } }))
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: inertia.utilisateur } } }),
    router: { patch: inertia.patch },
}))

import { useMinuteurDeRepos } from '@/composables/useMinuteurDeRepos'

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
            { preserveScroll: true, preserveState: true },
        )
        expect(minuteur.showTimer.value).toBe(false)
    })

    it('lit le réglage du profil au départ', () => {
        inertia.utilisateur.auto_rest_timer = false

        expect(useMinuteurDeRepos().autoRestTimer.value).toBe(false)
    })
})
