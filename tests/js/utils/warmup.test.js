import { describe, it, expect } from 'vitest'
import { roundWeight, calculateWarmupSets } from '@/Utils/warmup'

const steps = [{ percent: 0 }, { percent: 40 }, { percent: 60 }, { percent: 80 }]

describe('roundWeight', () => {
    it('arrondit au multiple d’incrément le plus proche', () => {
        expect(roundWeight(43, 2.5)).toBe(42.5)
        expect(roundWeight(44, 2.5)).toBe(45)
    })

    it('arrondit la moitié vers le haut', () => {
        expect(roundWeight(41.25, 2.5)).toBe(42.5)
    })

    it('rend le poids inchangé quand l’incrément est nul', () => {
        // Sans ce garde, la division produirait Infinity puis NaN — donc un
        // poids affiché « NaN kg » plutôt qu'une valeur chargeable.
        expect(roundWeight(43, 0)).toBe(43)
    })
})

describe('calculateWarmupSets', () => {
    const options = { targetWeight: 100, barWeight: 20, roundingIncrement: 2.5 }

    it('traite un palier à 0 % comme la barre à vide, pas comme un pourcentage', () => {
        const [bar] = calculateWarmupSets(steps, options)

        expect(bar.weight).toBe(20)
        expect(bar.plateLoad).toBe('Vide')
    })

    it('calcule chaque palier et l’arrondit à l’incrément', () => {
        const sets = calculateWarmupSets(steps, options)

        expect(sets.map((s) => s.weight)).toEqual([20, 40, 60, 80])
    })

    it('ne descend jamais sous le poids de la barre', () => {
        // 10 % de 30 kg font 3 kg : une barre de 20 ne peut pas peser moins
        // qu'elle-même, le palier doit être ramené au plancher.
        const sets = calculateWarmupSets([{ percent: 10 }], {
            targetWeight: 30,
            barWeight: 20,
            roundingIncrement: 2.5,
        })

        expect(sets[0].weight).toBe(20)
        expect(sets[0].plateLoad).toBe('Vide')
    })

    it('donne la charge par côté, disques seuls', () => {
        const sets = calculateWarmupSets([{ percent: 80 }], options)

        // 80 kg - 20 de barre = 60, soit 30 par côté.
        expect(sets[0].plateLoad).toBe('30kg')
    })

    it('conserve les autres champs du palier', () => {
        const sets = calculateWarmupSets([{ percent: 50, reps: 5 }], options)

        expect(sets[0].reps).toBe(5)
        expect(sets[0].percent).toBe(50)
    })

    it('applique l’arrondi grossier d’une salle sans petits disques', () => {
        // Incrément de 5 kg : 65 % de 100 valent 65, qui reste chargeable ;
        // 62 % valent 62 et doivent remonter à 60.
        const sets = calculateWarmupSets([{ percent: 65 }, { percent: 62 }], {
            ...options,
            roundingIncrement: 5,
        })

        expect(sets.map((s) => s.weight)).toEqual([65, 60])
    })

    it('rend une liste vide quand aucun palier n’est défini', () => {
        expect(calculateWarmupSets([], options)).toEqual([])
    })
})
