<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import ExerciseCategoryChart from '@/Components/Stats/ExerciseCategoryChart.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    exercises: Array,
    categories: Array,
    types: Array,
})

const showAddForm = ref(false)
const editingExercise = ref(null)
const searchQuery = ref('')
const activeCategory = ref('all')

const form = useForm({
    name: '',
    type: 'strength',
    category: '',
})

const editForm = useForm({
    name: '',
    type: '',
    category: '',
})

const submit = () => {
    form.post(route('exercises.store'), {
        onSuccess: () => {
            form.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (exercise) => {
    editingExercise.value = exercise.id
    editForm.name = exercise.name
    editForm.type = exercise.type
    editForm.category = exercise.category || ''
}

const cancelEdit = () => {
    editingExercise.value = null
    editForm.reset()
}

const updateExercise = (exercise) => {
    editForm.put(route('exercises.update', { exercise: exercise.id }), {
        onSuccess: () => {
            editingExercise.value = null
        },
    })
}

const deleteExercise = (id) => {
    if (confirm('Supprimer cet exercice ?')) {
        router.delete(route('exercises.destroy', { exercise: id }))
    }
}

// Filter exercises by search and category
const filteredExercises = computed(() => {
    return props.exercises.filter((exercise) => {
        const matchesSearch =
            !searchQuery.value || exercise.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        const matchesCategory = activeCategory.value === 'all' || exercise.category === activeCategory.value
        return matchesSearch && matchesCategory
    })
})

const groupedExercises = computed(() => {
    const groups = {}
    filteredExercises.value.forEach((exercise) => {
        const cat = exercise.category || 'Autres'
        if (!groups[cat]) {
            groups[cat] = []
        }
        groups[cat].push(exercise)
    })
    return groups
})

const categoryColors = {
    Pectoraux: 'bg-electric-orange',
    Dos: 'bg-vivid-violet',
    Épaules: 'bg-hot-pink',
    Bras: 'bg-cyan-pure text-text-main',
    Jambes: 'bg-neon-green text-text-main',
    Core: 'bg-magenta-pure',
    Cardio: 'bg-lime-pure text-text-main',
    Autres: 'bg-slate-500',
}

const categoryBorderColors = {
    Pectoraux: 'border-l-electric-orange',
    Dos: 'border-l-vivid-violet',
    Épaules: 'border-l-hot-pink',
    Bras: 'border-l-cyan-pure',
    Jambes: 'border-l-neon-green',
    Core: 'border-l-magenta-pure',
    Cardio: 'border-l-lime-pure',
    Autres: 'border-l-slate-400',
}

const typeIcons = {
    strength: 'fitness_center',
    cardio: 'directions_run',
    timed: 'timer',
}

const typeLabel = (type) => {
    const found = props.types.find((t) => t.value === type)
    return found ? found.label : type
}
</script>

<template>
    <Head title="Bibliothèque" />

    <AuthenticatedLayout liquid-variant="subtle">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-5xl font-black uppercase italic leading-none tracking-tighter text-text-main"
                    >
                        La<br />
                        <span class="text-gradient">Bibliothèque</span>
                    </h1>
                    <p class="mt-2 text-sm font-semibold uppercase tracking-wider text-text-muted">
                        {{ exercises.length }} exercices disponibles
                    </p>
                </div>
                <button
                    @click="showAddForm = true"
                    class="flex size-14 items-center justify-center rounded-2xl bg-gradient-main text-white shadow-lg shadow-orange-500/20 active:scale-95 sm:hidden"
                    data-testid="create-exercise-mobile-header"
                >
                    <span class="material-symbols-outlined text-4xl">add</span>
                </button>
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    class="hidden sm:flex"
                    data-testid="create-exercise-desktop"
                >
                    <span class="material-symbols-outlined mr-2">add</span>
                    Nouvel Exercice
                </GlassButton>
            </div>

            <!-- Stats Chart -->
            <div v-if="exercises.length > 0" class="animate-slide-up" style="animation-delay: 0.05s">
                <GlassCard padding="p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-display text-sm font-black uppercase tracking-wider text-text-main">
                            Répartition
                        </h3>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex-1">
                            <ExerciseCategoryChart :exercises="exercises" />
                        </div>
                    </div>
                </GlassCard>
            </div>

            <!-- Stats Chart -->
            <div v-if="exercises.length > 0" class="animate-slide-up" style="animation-delay: 0.05s">
                <GlassCard padding="p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-display text-sm font-black uppercase tracking-wider text-text-main">
                            Répartition
                        </h3>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex-1">
                            <ExerciseCategoryChart :exercises="exercises" />
                        </div>
                    </div>
                </GlassCard>
            </div>

            <!-- Search Bar -->
            <div
                class="glass-panel-light flex animate-slide-up items-center gap-3 rounded-2xl p-3"
                style="animation-delay: 0.1s"
            >
                <span class="material-symbols-outlined text-[24px] text-text-muted">search</span>
                <input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Recherche exercices..."
                    class="flex-1 border-none bg-transparent text-lg text-text-main placeholder:text-text-muted/50 focus:outline-none focus:ring-0"
                />
                <div
                    class="hidden items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-text-muted/40 sm:flex"
                >
                    <span class="material-symbols-outlined text-sm">keyboard</span>
                    ⌘K
                </div>
            </div>

            <!-- Category Pills -->
            <div class="hide-scrollbar flex animate-slide-up gap-2 overflow-x-auto pb-2" style="animation-delay: 0.15s">
                <button
                    @click="activeCategory = 'all'"
                    :class="[
                        'category-pill flex-shrink-0 transition-all',
                        activeCategory === 'all'
                            ? 'bg-text-main text-white shadow-lg'
                            : 'border border-slate-200 bg-white text-text-main',
                    ]"
                >
                    <span class="material-symbols-outlined text-lg">apps</span>
                    Tous
                </button>
                <button
                    v-for="cat in categories"
                    :key="cat"
                    @click="activeCategory = cat"
                    :class="[
                        'category-pill flex-shrink-0 transition-all',
                        activeCategory === cat
                            ? `${categoryColors[cat] || 'bg-slate-500'} text-white`
                            : 'border border-slate-200 bg-white text-text-main',
                    ]"
                >
                    {{ cat }}
                </button>
            </div>

            <!-- Add Form Modal -->
            <GlassCard v-if="showAddForm" class="animate-scale-in" variant="solid">
                <h3 class="mb-5 font-display text-xl font-black uppercase text-text-main">Nouvel exercice</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom de l'exercice"
                        placeholder="Ex: Développé couché"
                        :error="form.errors.name"
                    />
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Type</label>
                            <select v-model="form.type" class="glass-input w-full">
                                <option v-for="t in types" :key="t.value" :value="t.value">
                                    {{ t.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.type" class="mt-2 text-sm font-medium text-red-600">
                                {{ form.errors.type }}
                            </p>
                        </div>
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Catégorie</label>
                            <select v-model="form.category" class="glass-input w-full">
                                <option value="">— Aucune —</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">
                                    {{ cat }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <GlassButton
                        type="submit"
                        variant="primary"
                        class="w-full"
                        :loading="form.processing"
                        data-testid="submit-exercise-button"
                    >
                        Créer l'exercice
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- Error display -->
            <GlassCard v-if="$page.props.errors?.exercise" class="border-red-500 bg-red-50">
                <p class="text-center font-bold text-red-600">{{ $page.props.errors.exercise }}</p>
            </GlassCard>

            <!-- Empty State -->
            <div v-if="filteredExercises.length === 0 && !searchQuery" class="animate-slide-up">
                <GlassCard class="py-12 text-center">
                    <div class="mb-4 text-6xl">🏋️</div>
                    <p class="text-lg font-bold text-text-main">Aucun exercice pour l'instant</p>
                    <p class="mt-1 text-text-muted">Commence par créer ton premier exercice</p>
                    <GlassButton
                        variant="primary"
                        class="mt-6"
                        @click="showAddForm = true"
                        data-testid="create-exercise-button"
                    >
                        <span class="material-symbols-outlined mr-2">add</span>
                        Créer le premier exercice
                    </GlassButton>
                </GlassCard>
            </div>

            <!-- No Search Results -->
            <div v-else-if="filteredExercises.length === 0" class="animate-slide-up">
                <GlassCard class="py-8 text-center">
                    <span class="material-symbols-outlined mb-3 text-6xl text-text-muted/30">search_off</span>
                    <p class="font-bold text-text-main">Aucun résultat pour "{{ searchQuery }}"</p>
                </GlassCard>
            </div>

            <!-- Exercises List by Category -->
            <div v-else class="animate-slide-up space-y-8" style="animation-delay: 0.2s">
                <div v-for="(exercisesInCat, category) in groupedExercises" :key="category">
                    <div class="mb-3 flex items-center gap-2 px-1">
                        <h3 class="text-[10px] font-black uppercase tracking-[0.25em] text-text-muted/60">
                            {{ category }}
                        </h3>
                        <div class="h-px flex-1 bg-slate-100"></div>
                        <span class="text-[10px] font-black text-text-muted/30">
                            {{ exercisesInCat.length }}
                        </span>
                    </div>

                    <div class="space-y-3">
                        <GlassCard
                            v-for="exercise in exercisesInCat"
                            :key="exercise.id"
                            padding="p-4"
                            :class="[
                                'group relative overflow-hidden transition-all duration-300',
                                'border-l-[6px]',
                                categoryBorderColors[category] || 'border-l-slate-300',
                            ]"
                        >
                            <!-- View Mode -->
                            <div v-if="editingExercise !== exercise.id" class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div
                                        :class="[
                                            'flex size-14 items-center justify-center rounded-2xl',
                                            exercise.type === 'strength'
                                                ? 'bg-electric-orange/10 text-electric-orange'
                                                : exercise.type === 'cardio'
                                                  ? 'bg-neon-green/30 text-text-main'
                                                  : 'bg-cyan-pure/10 text-cyan-pure',
                                        ]"
                                    >
                                        <span class="material-symbols-outlined text-3xl">
                                            {{ typeIcons[exercise.type] || 'fitness_center' }}
                                        </span>
                                    </div>
                                    <div>
                                        <div
                                            class="font-display text-lg font-bold uppercase italic leading-tight text-text-main"
                                        >
                                            {{ exercise.name }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs font-semibold uppercase tracking-wider text-text-muted"
                                        >
                                            {{ typeLabel(exercise.type) }}
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="flex items-center gap-2 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100"
                                >
                                    <button
                                        @click="startEdit(exercise)"
                                        class="flex size-10 items-center justify-center rounded-xl text-text-muted transition-all hover:bg-electric-orange/10 hover:text-electric-orange"
                                        data-testid="edit-exercise-button"
                                    >
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <button
                                        @click="deleteExercise(exercise.id)"
                                        class="flex size-10 items-center justify-center rounded-xl text-text-muted transition-all hover:bg-red-50 hover:text-red-500"
                                        data-testid="delete-exercise-button"
                                    >
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <form v-else @submit.prevent="updateExercise(exercise)" class="space-y-4">
                                <GlassInput
                                    v-model="editForm.name"
                                    placeholder="Nom de l'exercice"
                                    :error="editForm.errors.name"
                                />
                                <div class="grid grid-cols-2 gap-3">
                                    <select v-model="editForm.type" class="glass-input text-sm">
                                        <option v-for="t in types" :key="t.value" :value="t.value">
                                            {{ t.label }}
                                        </option>
                                    </select>
                                    <select v-model="editForm.category" class="glass-input text-sm">
                                        <option value="">— Aucune —</option>
                                        <option v-for="cat in categories" :key="cat" :value="cat">
                                            {{ cat }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex gap-2">
                                    <GlassButton
                                        type="submit"
                                        variant="primary"
                                        size="sm"
                                        :loading="editForm.processing"
                                        data-testid="save-exercise-button"
                                    >
                                        Sauvegarder
                                    </GlassButton>
                                    <GlassButton type="button" variant="ghost" size="sm" @click="cancelEdit">
                                        Annuler
                                    </GlassButton>
                                </div>
                            </form>
                        </GlassCard>
                    </div>
                </div>
            </div>
            <!-- List Padding for Mobile Bottom Nav -->
            <div class="h-24 sm:hidden"></div>
        </div>
    </AuthenticatedLayout>
</template>
