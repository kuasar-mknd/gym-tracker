import { describe, it, expect } from 'vitest'

import {
    SEUIL_D_UNE_SEANCE_OUBLIEE_S,
    estUneSeanceOubliee,
    finProposeeDUneSeance,
    jourEtHeure,
} from '@/Utils/seanceOubliee'

const MAINTENANT = new Date('2026-10-19T12:00:00.000Z')
const ilYA = (secondes) => new Date(MAINTENANT.getTime() - secondes * 1000).toISOString()

describe('estUneSeanceOubliee', () => {
    it('tient à douze heures près : exactement douze heures n’est pas oublié, une seconde de plus si', () => {
        expect(SEUIL_D_UNE_SEANCE_OUBLIEE_S).toBe(12 * 3600)
        expect(estUneSeanceOubliee(ilYA(12 * 3600), MAINTENANT)).toBe(false)
        expect(estUneSeanceOubliee(ilYA(12 * 3600 + 1), MAINTENANT)).toBe(true)
        expect(estUneSeanceOubliee(ilYA(60), MAINTENANT)).toBe(false)
    })

    it('ne tient jamais pour oubliée une séance dont le début manque, ne se lit pas ou est à venir', () => {
        expect(estUneSeanceOubliee(null, MAINTENANT)).toBe(false)
        expect(estUneSeanceOubliee('pas une date', MAINTENANT)).toBe(false)
        expect(estUneSeanceOubliee(ilYA(-3600), MAINTENANT)).toBe(false)
    })
})

describe('finProposeeDUneSeance', () => {
    const debut = ilYA(30 * 3600)

    it('est la série la plus récente, par sa modification, sinon par sa création', () => {
        const seance = {
            started_at: debut,
            workout_lines: [
                { sets: [{ updated_at: ilYA(29 * 3600) }] },
                {
                    sets: [
                        { created_at: ilYA(28 * 3600) },
                        { created_at: ilYA(27 * 3600), updated_at: ilYA(26 * 3600) },
                    ],
                },
            ],
        }

        expect(finProposeeDUneSeance(seance, MAINTENANT)).toEqual({ instant: ilYA(26 * 3600), serie: true })
    })

    it('est le début d’une séance sans série', () => {
        expect(finProposeeDUneSeance({ started_at: debut, workout_lines: [] }, MAINTENANT)).toEqual({
            instant: debut,
            serie: false,
        })
        expect(finProposeeDUneSeance({ started_at: debut }, MAINTENANT)).toEqual({ instant: debut, serie: false })
    })

    it('laisse de côté une série d’avant le début, à venir ou sans heure lisible', () => {
        const seance = {
            started_at: debut,
            workout_lines: [
                {
                    sets: [
                        { updated_at: ilYA(40 * 3600) },
                        { updated_at: ilYA(-3600) },
                        { updated_at: 'n’importe quoi' },
                        {},
                    ],
                },
            ],
        }

        expect(finProposeeDUneSeance(seance, MAINTENANT)).toEqual({ instant: debut, serie: false })
    })
})

describe('jourEtHeure', () => {
    it('écrit le jour, le mois et l’heure', () => {
        expect(jourEtHeure(new Date(2026, 7, 26, 18, 42))).toBe('26/08 à 18:42')
    })
})
