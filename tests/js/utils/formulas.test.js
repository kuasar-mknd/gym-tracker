import { describe, it, expect } from 'vitest'

import { oneRepMax, wilksCoefficient, wilksScore, basalMetabolicRate, macroTargets } from '@/Utils/formulas'

/**
 * These formulas lived inside the page modules, reachable only by rendering the
 * page they were embedded in, so none of them had a test — and the Wilks and
 * macro calculations both shipped wrong.
 *
 * Where a published reference exists, it is asserted against the published
 * value, not against the implementation. A test that recomputes the formula it
 * is testing proves only that the code equals itself.
 */
describe('oneRepMax — Epley', () => {
    it('matches the published estimate', () => {
        // Epley: 100 kg × 5 → 100 × (1 + 5/30) = 116.67
        expect(oneRepMax(100, 5)).toBeCloseTo(116.67, 2)
    })

    it('leaves a single repetition alone', () => {
        // One rep is already a max. Running it through the formula would
        // inflate it by 3.3%.
        expect(oneRepMax(140, 1)).toBe(140)
    })

    it('reads figures typed into a form', () => {
        expect(oneRepMax('100', '5')).toBeCloseTo(116.67, 2)
    })

    it('returns zero rather than NaN on an empty form', () => {
        expect(oneRepMax('', '')).toBe(0)
        expect(oneRepMax(0, 5)).toBe(0)
        expect(oneRepMax(100, -3)).toBe(0)
    })
})

describe('wilks', () => {
    it('matches the published coefficient for a 100 kg man', () => {
        // Wilks 1994 tables give ≈ 0.6086 at 100 kg bodyweight.
        expect(wilksCoefficient(100, 'male')).toBeCloseTo(0.6086, 3)
    })

    it('matches the published coefficient for a 60 kg woman', () => {
        // Wilks 1994 tables give ≈ 1.1149 at 60 kg bodyweight.
        expect(wilksCoefficient(60, 'female')).toBeCloseTo(1.1149, 3)
    })

    /**
     * Two properties of the Wilks curve that hold whatever the coefficients
     * are. They catch a transcription error in the constants, which comparing
     * against a value recomputed from those same constants cannot.
     */
    it('falls as bodyweight rises', () => {
        const coefficients = [60, 75, 90, 105, 120].map((bodyWeight) => wilksCoefficient(bodyWeight, 'male'))

        coefficients.forEach((coefficient, index) => {
            if (index > 0) {
                expect(coefficient).toBeLessThan(coefficients[index - 1])
            }
        })
    })

    it('sits higher for a woman than for a man of the same bodyweight', () => {
        expect(wilksCoefficient(75, 'female')).toBeGreaterThan(wilksCoefficient(75, 'male'))
    })

    it('scores a total as the coefficient times the total', () => {
        expect(wilksScore({ bodyWeight: 100, lifted: 500, gender: 'male' })).toBeCloseTo(500 * 0.6086, 1)
    })

    it('gives the same score whichever unit the figures were typed in', () => {
        // 220.462 lb is 100 kg; 1102.31 lb is 500 kg. The score is a ratio, so
        // the unit must cancel out.
        const inKilos = wilksScore({ bodyWeight: 100, lifted: 500, gender: 'male' })
        const inPounds = wilksScore({ bodyWeight: 220.462, lifted: 1102.31, gender: 'male', unit: 'lbs' })

        expect(inPounds).toBeCloseTo(inKilos, 2)
    })

    it('returns a number, not a string', () => {
        // It used to return toFixed(2), so every caller had to parse it back
        // before it could be compared or summed.
        expect(typeof wilksScore({ bodyWeight: 80, lifted: 400, gender: 'female' })).toBe('number')
    })

    it('returns zero rather than NaN on an empty form', () => {
        expect(wilksScore({ bodyWeight: '', lifted: '', gender: 'male' })).toBe(0)
    })
})

describe('basalMetabolicRate — Mifflin-St Jeor', () => {
    it('matches the published value for a man', () => {
        // 10×80 + 6.25×180 − 5×30 + 5 = 1780
        expect(basalMetabolicRate({ weight: 80, height: 180, age: 30, gender: 'male' })).toBe(1780)
    })

    it('matches the published value for a woman', () => {
        // 10×60 + 6.25×165 − 5×30 − 161 = 1320.25
        expect(basalMetabolicRate({ weight: 60, height: 165, age: 30, gender: 'female' })).toBeCloseTo(1320.25, 2)
    })
})

describe('macroTargets', () => {
    const profile = {
        weight: 80,
        height: 180,
        age: 30,
        gender: 'male',
        activityLevel: 'moderate',
        goal: 'maintain',
    }

    it('applies the activity multiplier to the BMR', () => {
        // 1780 × 1.55 = 2759
        expect(macroTargets(profile).tdee).toBe(2759)
    })

    it('takes 500 off for a cut and adds 300 for a bulk', () => {
        expect(macroTargets({ ...profile, goal: 'cut' }).targetCalories).toBe(2259)
        expect(macroTargets({ ...profile, goal: 'bulk' }).targetCalories).toBe(3059)
    })

    it('never drops a man below 1500 calories', () => {
        const tiny = { ...profile, weight: 45, height: 150, age: 70, activityLevel: 'sedentary', goal: 'cut' }

        expect(macroTargets(tiny).targetCalories).toBe(1500)
    })

    it('never drops a woman below 1200 calories', () => {
        const tiny = {
            ...profile,
            gender: 'female',
            weight: 40,
            height: 145,
            age: 70,
            activityLevel: 'sedentary',
            goal: 'cut',
        }

        expect(macroTargets(tiny).targetCalories).toBe(1200)
    })

    it('keeps the macros within the calorie target', () => {
        const { targetCalories, protein, fat, carbs } = macroTargets(profile)

        // Rounding each macro to the gram cannot drift more than a few calories
        // from the target it was split out of.
        expect(protein * 4 + fat * 9 + carbs * 4).toBeCloseTo(targetCalories, -1)
    })

    it('cuts fat rather than protein when the target cannot cover both', () => {
        // A heavy person on an aggressive cut: 2 g/kg protein alone eats most of
        // the target, so the fat figure has to give way — but not below 30 g.
        const heavyCut = { ...profile, weight: 120, activityLevel: 'sedentary', goal: 'cut' }
        const { protein, fat } = macroTargets(heavyCut)

        expect(protein).toBe(240)
        expect(fat).toBeGreaterThanOrEqual(30)
    })

    it('returns zeroes rather than NaN on an empty form', () => {
        expect(macroTargets({ weight: '', height: '', age: '', gender: 'male', activityLevel: 'moderate' })).toEqual({
            tdee: 0,
            targetCalories: 0,
            protein: 0,
            fat: 0,
            carbs: 0,
        })
    })
})
