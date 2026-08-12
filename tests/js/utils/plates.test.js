import { describe, it, expect } from 'vitest'
import { calculatePlates, actualWeight } from '@/Utils/plates'

/** Inventaire olympique courant, en paires. */
const gym = [
    { weight: 25, quantity: 4 },
    { weight: 20, quantity: 4 },
    { weight: 10, quantity: 4 },
    { weight: 5, quantity: 4 },
    { weight: 2.5, quantity: 4 },
    { weight: 1.25, quantity: 4 },
]

describe('calculatePlates', () => {
    it('charge le plus lourd en premier, par côté', () => {
        // 100 kg - 20 de barre = 80, soit 40 par côté = 25 + 10 + 5
        expect(calculatePlates(100, 20, gym).map((p) => p.weight)).toEqual([25, 10, 5])
    })

    it('ne compte que les paires complètes', () => {
        // Trois disques de 20 ne forment qu'une paire utilisable : le reste
        // doit être atteint avec des disques plus légers.
        const plates = calculatePlates(80, 20, [
            { weight: 20, quantity: 3 },
            { weight: 10, quantity: 4 },
        ])

        expect(plates.map((p) => p.weight)).toEqual([20, 10])
    })

    it('ne charge rien quand la cible atteint ou passe sous le poids de la barre', () => {
        expect(calculatePlates(20, 20, gym)).toEqual([])
        expect(calculatePlates(15, 20, gym)).toEqual([])
    })

    it('ne charge rien sans cible ou sans barre', () => {
        expect(calculatePlates(0, 20, gym)).toEqual([])
        expect(calculatePlates(100, 0, gym)).toEqual([])
    })

    it('approche au mieux une cible inatteignable, sans la dépasser', () => {
        // 47,5 par côté avec seulement des 20 : deux disques, soit 40. Le
        // reliquat de 7,5 reste non chargé plutôt que d'être arrondi au-dessus.
        const plates = calculatePlates(115, 20, [{ weight: 20, quantity: 10 }])

        expect(plates.map((p) => p.weight)).toEqual([20, 20])
        expect(actualWeight(plates, 20)).toBe(100)
    })

    it('accepte des poids en chaîne, comme ceux venus de la base', () => {
        const plates = calculatePlates(60, 20, [{ weight: '20', quantity: 4 }])

        expect(plates.map((p) => p.weight)).toEqual([20])
    })

    it('ignore un inventaire aux poids inexploitables', () => {
        const plates = calculatePlates(100, 20, [
            { weight: 'abc', quantity: 4 },
            { weight: 0, quantity: 4 },
            { weight: 25, quantity: 2 },
        ])

        expect(plates.map((p) => p.weight)).toEqual([25])
    })

    it('atteint exactement une cible en demi-disques sans boucler sur un reliquat flottant', () => {
        // 0,625 par côté trois fois de suite : en binaire le reliquat ne tombe
        // pas à zéro pile. Sans la tolérance, la boucle chercherait encore des
        // disques pour un poids qui n'existe plus.
        const plates = calculatePlates(27.5, 20, [{ weight: 1.25, quantity: 8 }])

        expect(plates.map((p) => p.weight)).toEqual([1.25, 1.25, 1.25])
        expect(actualWeight(plates, 20)).toBeCloseTo(27.5, 10)
    })

    it('ne charge rien quand l’inventaire est vide', () => {
        expect(calculatePlates(100, 20, [])).toEqual([])
        expect(calculatePlates(100, 20)).toEqual([])
    })
})

describe('actualWeight', () => {
    it('compte les disques des deux côtés', () => {
        expect(actualWeight([{ weight: 25 }, { weight: 10 }], 20)).toBe(90)
    })

    it('rend le poids de la barre quand rien n’est chargé', () => {
        expect(actualWeight([], 20)).toBe(20)
    })

    it('correspond à la cible quand elle est atteignable', () => {
        const plates = calculatePlates(100, 20, gym)

        expect(actualWeight(plates, 20)).toBe(100)
    })
})
