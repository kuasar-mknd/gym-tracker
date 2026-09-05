import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

const inertia = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn(), formulaire: null }))
const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: inertia.post, patch: inertia.patch },
    useForm: (champs) => {
        inertia.formulaire = { ...champs, transform: vi.fn(), patch: vi.fn() }
        inertia.formulaire.transform.mockReturnValue(inertia.formulaire)

        return inertia.formulaire
    },
}))
vi.mock('@/composables/useHaptics', () => haptics)

import { useReglagesDeLaSeance } from '@/composables/useReglagesDeLaSeance'

const monter = () => {
    const viderLesEcritures = vi.fn().mockResolvedValue(undefined)
    const localWorkout = ref({ id: 5, name: 'Jambes', started_at: '2026-09-05T10:00:00.000000Z', notes: null })

    return { viderLesEcritures, ...useReglagesDeLaSeance({ localWorkout, viderLesEcritures }) }
}

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
})

describe('les réglages de la séance', () => {
    it('enregistre la séance comme modèle, le bouton occupé le temps de l’aller-retour', () => {
        const reglages = monter()

        reglages.saveAsTemplate()
        expect(reglages.savingTemplate.value).toBe(true)
        expect(inertia.post).toHaveBeenCalledWith(
            '/templates.save-from-workout/5',
            {},
            expect.objectContaining({ preserveScroll: true }),
        )

        inertia.post.mock.calls[0][2].onFinish()
        expect(reglages.savingTemplate.value).toBe(false)
    })

    it('vide les écritures en attente avant de clore la séance, puis ferme la question et vibre', async () => {
        const reglages = monter()
        reglages.finishWorkout()
        expect(reglages.showFinishModal.value).toBe(true)

        await reglages.confirmFinishWorkout()

        expect(reglages.viderLesEcritures).toHaveBeenCalledTimes(1)
        expect(inertia.patch).toHaveBeenCalledWith('/workouts.update/5', { is_finished: true }, expect.any(Object))
        expect(reglages.viderLesEcritures.mock.invocationCallOrder[0]).toBeLessThan(
            inertia.patch.mock.invocationCallOrder[0],
        )

        const options = inertia.patch.mock.calls[0][2]
        options.onStart()
        options.onSuccess()
        expect(reglages.showFinishModal.value).toBe(false)
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('success')
    })

    it('envoie les réglages avec le début en UTC, et referme la modale quand le serveur accepte', () => {
        const reglages = monter()
        reglages.showSettingsModal.value = true
        expect(reglages.settingsForm.name).toBe('Jambes')
        expect(reglages.settingsForm.notes).toBe('')

        reglages.updateSettings()

        const transformer = inertia.formulaire.transform.mock.calls[0][0]
        expect(transformer({ name: 'Dos', started_at: '2026-09-05T12:00' }).started_at).toMatch(/Z$|\+00:00$/)
        expect(inertia.formulaire.patch).toHaveBeenCalledWith(
            '/workouts.update/5',
            expect.objectContaining({ preserveScroll: true }),
        )

        inertia.formulaire.patch.mock.calls[0][1].onSuccess()
        expect(reglages.showSettingsModal.value).toBe(false)
    })
})
