import { describe, expect, it } from 'vitest'
import { courbeDansLUniteDuDernierReleve } from '@/Utils/mesuresEnUnite'

describe('courbeDansLUniteDuDernierReleve', () => {
    it('ramène chaque relevé à l’unité du dernier', () => {
        const courbe = courbeDansLUniteDuDernierReleve([
            { id: 1, value: 80, unit: 'cm' },
            { id: 2, value: 31.5, unit: 'in' },
        ])

        expect(courbe.map((point) => point.unit)).toEqual(['in', 'in'])
        expect(courbe[0].value).toBe(31.5)
        expect(courbe[1].value).toBe(31.5)
    })

    it('convertit des pouces en centimètres quand le dernier relevé est en centimètres', () => {
        const courbe = courbeDansLUniteDuDernierReleve([
            { id: 1, value: 31.5, unit: 'in' },
            { id: 2, value: 80, unit: 'cm' },
        ])

        expect(courbe[0]).toMatchObject({ value: 80.01, unit: 'cm' })
    })

    it('rend une courbe vide pour un historique vide', () => {
        expect(courbeDansLUniteDuDernierReleve([])).toEqual([])
    })
})
