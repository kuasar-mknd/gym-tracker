import { describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'

import { useEnsuiteDuRepos } from '@/composables/useEnsuiteDuRepos'

const seance = () =>
    ref(
        reactive({
            workout_lines: [
                {
                    id: 1,
                    sets: [
                        { id: 10, is_completed: true },
                        { id: 11, is_completed: false },
                        { id: 12, is_completed: false },
                        { id: 13, is_completed: false },
                    ],
                },
                { id: 2, sets: [{ id: 20, is_completed: true }] },
            ],
        }),
    )

describe('ce que dit la carte du repos de la suite', () => {
    it('ne dit rien avant la première validation', () => {
        const { ensuiteDuRepos } = useEnsuiteDuRepos({ localWorkout: seance(), apresValidation: vi.fn() })

        expect(ensuiteDuRepos.value).toBe('')
    })

    it('nomme la série suivante non validée, sur le nombre de séries de l’exercice', () => {
        const localWorkout = seance()
        const { apresLaValidation, ensuiteDuRepos } = useEnsuiteDuRepos({ localWorkout, apresValidation: vi.fn() })

        apresLaValidation(90, localWorkout.value.workout_lines[0].sets[0])

        expect(ensuiteDuRepos.value).toBe('Ensuite, série 2 sur 4')
    })

    it('saute les séries déjà validées', () => {
        const localWorkout = seance()
        const { apresLaValidation, ensuiteDuRepos } = useEnsuiteDuRepos({ localWorkout, apresValidation: vi.fn() })

        localWorkout.value.workout_lines[0].sets[1].is_completed = true
        apresLaValidation(90, localWorkout.value.workout_lines[0].sets[0])

        expect(ensuiteDuRepos.value).toBe('Ensuite, série 3 sur 4')
    })

    it('dit que l’exercice est terminé après la dernière série', () => {
        const localWorkout = seance()
        const { apresLaValidation, ensuiteDuRepos } = useEnsuiteDuRepos({ localWorkout, apresValidation: vi.fn() })

        apresLaValidation(90, localWorkout.value.workout_lines[1].sets[0])

        expect(ensuiteDuRepos.value).toBe('Exercice terminé')
    })

    it('lance le repos avec le temps de l’exercice', () => {
        const apresValidation = vi.fn()
        const localWorkout = seance()
        const { apresLaValidation } = useEnsuiteDuRepos({ localWorkout, apresValidation })

        apresLaValidation(75, localWorkout.value.workout_lines[0].sets[0])

        expect(apresValidation).toHaveBeenCalledWith(75)
    })
})
