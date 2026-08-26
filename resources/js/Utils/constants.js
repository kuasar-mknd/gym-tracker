export const EXERCISE_CATEGORIES = ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio']

export const EXERCISE_TYPES = [
    { value: 'strength', label: 'Force' },
    { value: 'cardio', label: 'Cardio' },
    { value: 'timed', label: 'Temps' },
]

/**
 * La pastille d'une catégorie : un fond ET son texte, sous un seul nom.
 *
 * Ces valeurs portaient le fond et parfois le texte — `bg-accent-info
 * text-text-main` — et les appelants recollaient `text-text-on-dark-accent` par-dessus. Comme
 * les deux classes ont la même spécificité, le gagnant dépendait de l'ordre du
 * CSS généré, que personne n'écrit : « Bras » pouvait rendre du blanc sur cyan
 * à 1,54:1. Un seul nom, et la question ne se pose plus.
 */
export const CATEGORY_COLORS = {
    Pectoraux: 'category-fill-chest',
    Dos: 'category-fill-back',
    Épaules: 'category-fill-shoulders',
    Bras: 'category-fill-arms',
    Jambes: 'category-fill-legs',
    Core: 'category-fill-core',
    Abdominaux: 'category-fill-core',
    Cardio: 'category-fill-cardio',
    Autres: 'category-fill-other',
}

export const CATEGORY_BORDER_COLORS = {
    Pectoraux: 'border-l-category-chest',
    Dos: 'border-l-category-back',
    Épaules: 'border-l-category-shoulders',
    Bras: 'border-l-category-arms',
    Jambes: 'border-l-category-legs',
    Core: 'border-l-category-core',
    Abdominaux: 'border-l-category-core',
    Cardio: 'border-l-category-cardio',
    Autres: 'border-l-category-other',
}

export const TYPE_ICONS = {
    strength: 'fitness_center',
    cardio: 'directions_run',
    timed: 'timer',
}
