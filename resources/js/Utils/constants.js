export const EXERCISE_CATEGORIES = ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio']

export const EXERCISE_TYPES = [
    { value: 'strength', label: 'Force' },
    { value: 'cardio', label: 'Cardio' },
    { value: 'timed', label: 'Temps' },
]

export const CATEGORY_COLORS = {
    Pectoraux: 'bg-electric-orange',
    Dos: 'bg-vivid-violet',
    Épaules: 'bg-hot-pink',
    Bras: 'bg-cyan-pure text-text-main',
    Jambes: 'bg-neon-green text-text-main',
    Core: 'bg-magenta-pure',
    Cardio: 'bg-lime-pure text-text-main',
    Autres: 'bg-slate-500',
}

export const CATEGORY_BORDER_COLORS = {
    Pectoraux: 'border-l-electric-orange',
    Dos: 'border-l-vivid-violet',
    Épaules: 'border-l-hot-pink',
    Bras: 'border-l-cyan-pure',
    Jambes: 'border-l-neon-green',
    Core: 'border-l-magenta-pure',
    Cardio: 'border-l-lime-pure',
    Autres: 'border-l-slate-400',
}

export const TYPE_ICONS = {
    strength: 'fitness_center',
    cardio: 'directions_run',
    timed: 'timer',
}
