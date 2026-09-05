import { describe, it, expect } from 'vitest'
import { fusionnerLaSeance } from '@/Utils/fusionDeSeance'

const garde = (extra = {}) => ({
    estNonSynchronisee: () => false,
    validationEnVol: () => false,
    ordreLocalPrime: false,
    ...extra,
})

describe('fusionner la séance rendue par le serveur avec celle de l’écran', () => {
    it('part de la copie du serveur, en tableaux, sans toucher à l’original', () => {
        const server = { id: 5, workout_lines: { a: { id: 1, sets: null } } }

        const fusion = fusionnerLaSeance(server, null, garde())

        expect(fusion.workout_lines).toEqual([{ id: 1, sets: [] }])
        expect(Array.isArray(server.workout_lines)).toBe(false)
        expect(fusionnerLaSeance({ id: 5 }, { workout_lines: [] }, garde()).workout_lines).toEqual([])
    })

    it('garde ce que le serveur n’a jamais vu : les séries et les lignes encore provisoires', () => {
        const server = { id: 5, workout_lines: [{ id: 1, sets: [{ id: 11, weight: 80 }] }] }
        const local = {
            workout_lines: [
                {
                    id: 1,
                    sets: [
                        { id: 11, weight: 80 },
                        { id: 'temp-1', weight: 85 },
                    ],
                },
                { id: 'temp-L', sets: [] },
            ],
        }

        const fusion = fusionnerLaSeance(server, local, garde())

        expect(fusion.workout_lines.map((l) => l.id)).toEqual([1, 'temp-L'])
        expect(fusion.workout_lines[0].sets.map((s) => s.id)).toEqual([11, 'temp-1'])
    })

    it('garde la copie locale d’une série non synchronisée, et la clef de chaque rangée', () => {
        const server = {
            workout_lines: [
                {
                    id: 1,
                    sets: [
                        { id: 11, weight: 80 },
                        { id: 12, weight: 50 },
                    ],
                },
            ],
        }
        const local = {
            workout_lines: [
                {
                    id: 1,
                    _rowKey: 'row-1',
                    sets: [
                        { id: 11, weight: 99, _rowKey: 'row-2' },
                        { id: 12, weight: 50, _rowKey: 'row-3' },
                    ],
                },
            ],
        }

        const fusion = fusionnerLaSeance(server, local, garde({ estNonSynchronisee: (set) => set.id === 11 }))

        expect(fusion.workout_lines[0]._rowKey).toBe('row-1')
        expect(fusion.workout_lines[0].sets[0]).toBe(local.workout_lines[0].sets[0])
        expect(fusion.workout_lines[0].sets[1]).toMatchObject({ id: 12, weight: 50, _rowKey: 'row-3' })
    })

    it('garde la coche locale d’une validation encore en vol', () => {
        const server = { workout_lines: [{ id: 1, sets: [{ id: 12, is_completed: false }] }] }
        const local = { workout_lines: [{ id: 1, sets: [{ id: 12, is_completed: true }] }] }

        const fusion = fusionnerLaSeance(server, local, garde({ validationEnVol: (set) => set.id === 12 }))

        expect(fusion.workout_lines[0].sets[0].is_completed).toBe(true)
        expect(fusionnerLaSeance(server, local, garde()).workout_lines[0].sets[0].is_completed).toBe(false)
    })

    it('garde l’ordre de l’écran tant qu’un déplacement vole, celui du serveur sinon', () => {
        const server = {
            workout_lines: [
                { id: 1, sets: [] },
                { id: 2, sets: [] },
            ],
        }
        const local = {
            workout_lines: [
                { id: 2, sets: [] },
                { id: 1, sets: [] },
            ],
        }

        expect(
            fusionnerLaSeance(server, local, garde({ ordreLocalPrime: true })).workout_lines.map((l) => l.id),
        ).toEqual([2, 1])
        expect(fusionnerLaSeance(server, local, garde()).workout_lines.map((l) => l.id)).toEqual([1, 2])
    })
})
