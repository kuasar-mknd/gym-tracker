/**
 * The arithmetic behind the Tools pages.
 *
 * These lived inside the page modules, which meant the only way to exercise a
 * formula was to render the page it was embedded in — so none of them had a
 * test, and two of them shipped wrong. Pure in, pure out: they can now be
 * checked against published reference values.
 *
 * Formatting stays with the pages. A formula returning a string, as the Wilks
 * one did, forces every caller to parse it back before it can be compared or
 * summed.
 */

/** Pounds in a kilogram, for the unit toggle on the Wilks page. */
const POUNDS_PER_KILO = 2.20462

/**
 * Wilks coefficients, 1994 formula. Same numbers the page carried.
 */
const WILKS_COEFFICIENTS = {
    male: [-216.0475144, 16.2606339, -0.002388645, -0.00113732, 7.01863e-6, -1.291e-8],
    female: [594.31747775582, -27.23842536447, 0.82112226871, -0.00930733913, 4.731582e-5, -9.054e-8],
}

/** Activity multipliers applied to BMR to reach TDEE. */
export const ACTIVITY_MULTIPLIERS = {
    sedentary: 1.2,
    light: 1.375,
    moderate: 1.55,
    very: 1.725,
    extra: 1.9,
}

/**
 * Estimated one-rep max, Epley.
 *
 * A single repetition is already a max, so it is returned untouched rather than
 * inflated by the formula.
 *
 * @param {number|string} weight - Load lifted.
 * @param {number|string} reps - Repetitions completed.
 * @returns {number} The estimate, or 0 when either input is missing or non-positive.
 */
export function oneRepMax(weight, reps) {
    const load = parseFloat(weight)
    const repetitions = parseFloat(reps)

    if (!load || !repetitions || load <= 0 || repetitions <= 0) {
        return 0
    }

    if (repetitions === 1) {
        return load
    }

    return load * (1 + repetitions / 30)
}

/**
 * The bodyweight coefficient the Wilks score multiplies a total by.
 *
 * Exposed on its own because it is the part that can be checked against a
 * published Wilks table — the score is just the total times this.
 *
 * @param {number} bodyWeightKg
 * @param {'male'|'female'} gender
 * @returns {number}
 */
export function wilksCoefficient(bodyWeightKg, gender) {
    const [a, b, c, d, e, f] = WILKS_COEFFICIENTS[gender === 'male' ? 'male' : 'female']

    const denominator =
        a +
        b * bodyWeightKg +
        c * Math.pow(bodyWeightKg, 2) +
        d * Math.pow(bodyWeightKg, 3) +
        e * Math.pow(bodyWeightKg, 4) +
        f * Math.pow(bodyWeightKg, 5)

    return 500 / denominator
}

/**
 * Wilks score for a total.
 *
 * @param {{bodyWeight: number|string, lifted: number|string, gender: 'male'|'female', unit?: 'kg'|'lbs'}} lift
 * @returns {number} The score, or 0 when either weight is missing.
 */
export function wilksScore({ bodyWeight, lifted, gender, unit = 'kg' }) {
    if (!bodyWeight || !lifted) {
        return 0
    }

    let weight = parseFloat(bodyWeight)
    let total = parseFloat(lifted)

    if (unit === 'lbs') {
        weight /= POUNDS_PER_KILO
        total /= POUNDS_PER_KILO
    }

    return total * wilksCoefficient(weight, gender)
}

/**
 * Basal metabolic rate, Mifflin-St Jeor.
 *
 * @param {{weight: number, height: number, age: number, gender: 'male'|'female'}} person
 * @returns {number} Calories per day.
 */
export function basalMetabolicRate({ weight, height, age, gender }) {
    const base = 10 * weight + 6.25 * height - 5 * age

    return gender === 'male' ? base + 5 : base - 161
}

/**
 * Daily calorie and macronutrient targets.
 *
 * Fat gives way before protein when the calorie target cannot cover both: the
 * protein figure is the one the training depends on. It never falls below 30 g,
 * which is the floor the page has always applied.
 *
 * @param {{weight: number|string, height: number|string, age: number|string,
 *   gender: 'male'|'female', activityLevel: keyof ACTIVITY_MULTIPLIERS,
 *   goal: 'cut'|'maintain'|'bulk'}} profile
 * @returns {{tdee: number, targetCalories: number, protein: number, fat: number, carbs: number}}
 */
export function macroTargets({ weight, height, age, gender, activityLevel, goal }) {
    const bodyWeight = parseFloat(weight)
    const bodyHeight = parseFloat(height)
    const years = parseFloat(age)

    if (!(bodyWeight > 0) || !(bodyHeight > 0) || !(years > 0)) {
        return { tdee: 0, targetCalories: 0, protein: 0, fat: 0, carbs: 0 }
    }

    const bmr = basalMetabolicRate({ weight: bodyWeight, height: bodyHeight, age: years, gender })
    const tdee = Math.round(bmr * ACTIVITY_MULTIPLIERS[activityLevel])

    let target = tdee

    if (goal === 'cut') {
        target -= 500
    }

    if (goal === 'bulk') {
        target += 300
    }

    // Floors below which the target stops being a diet.
    const floor = gender === 'male' ? 1500 : 1200
    target = Math.max(target, floor)

    const protein = Math.round(bodyWeight * 2)
    let fat = Math.round(bodyWeight * 0.9)

    const proteinCalories = protein * 4
    let remaining = target - (proteinCalories + fat * 9)

    if (remaining < 0) {
        fat = Math.max(30, Math.round((target - proteinCalories) / 9))
        remaining = target - (proteinCalories + fat * 9)
    }

    return {
        tdee,
        targetCalories: Math.round(target),
        protein,
        fat,
        carbs: Math.max(0, Math.round(remaining / 4)),
    }
}
