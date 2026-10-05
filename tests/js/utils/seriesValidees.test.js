import { describe, it, expect } from 'vitest'
import { peutEtablirUnRecord, seriesValidees, volumeDesSeriesValidees } from '@/Utils/seriesValidees'

/**
 * Les règles que le serveur applique au volume de la séance et aux records,
 * reprises pour les graphiques de la fiche exercice (#1956).
 */
const faite = (attributs) => ({ is_completed: true, is_warmup: false, ...attributs })

describe('seriesValidees', () => {
    it('ne garde que les séries cochées, échauffements compris', () => {
        const series = [
            faite({ weight: 100, reps: 5 }),
            faite({ weight: 140, reps: 5, is_completed: false }),
            faite({ weight: 40, reps: 10, is_warmup: true }),
        ]

        expect(seriesValidees(series).map((serie) => serie.weight)).toEqual([100, 40])
    })

    it('écarte une série sans drapeau plutôt que de la croire faite', () => {
        expect(seriesValidees([{ weight: 100, reps: 5 }])).toEqual([])
    })

    it('rend une liste vide pour une séance sans séries', () => {
        expect(seriesValidees(undefined)).toEqual([])
    })
})

describe('peutEtablirUnRecord', () => {
    it('suit les conditions du record : validée, hors échauffement, poids et répétitions', () => {
        expect(peutEtablirUnRecord(faite({ weight: 100, reps: 5 }))).toBe(true)
        expect(peutEtablirUnRecord(faite({ weight: '82.5', reps: '3' }))).toBe(true)
        expect(peutEtablirUnRecord(faite({ weight: 140, reps: 5, is_completed: false }))).toBe(false)
        expect(peutEtablirUnRecord(faite({ weight: 150, reps: 3, is_warmup: true }))).toBe(false)
        expect(peutEtablirUnRecord(faite({ weight: 0, reps: 12 }))).toBe(false)
        expect(peutEtablirUnRecord(faite({ weight: 200, reps: 0 }))).toBe(false)
        expect(peutEtablirUnRecord(faite({ weight: null, reps: 5 }))).toBe(false)
    })
})

describe('volumeDesSeriesValidees', () => {
    it('additionne les séries cochées comme le volume de la séance, échauffements validés compris', () => {
        const series = [
            faite({ weight: 100, reps: 5 }), // 500
            faite({ weight: 40, reps: 10, is_warmup: true }), // 400
            faite({ weight: 140, reps: 5, is_completed: false }), // jamais faite
        ]

        expect(volumeDesSeriesValidees(series)).toBe(900)
    })

    it('compte pour zéro un poids ou des répétitions manquants', () => {
        expect(
            volumeDesSeriesValidees([
                faite({ reps: 12 }),
                faite({ weight: 60, reps: null }),
                faite({ weight: 60, reps: 10 }),
            ]),
        ).toBe(600)
    })
})
