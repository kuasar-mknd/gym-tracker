<script setup>
/**
 * Exercises Index Page
 *
 * Displays the user's exercise library, categorized and searchable.
 * Supports creating, editing, and deleting exercises with optimistic UI updates
 * and haptic feedback. It uses a "Liquid Glass" design aesthetic.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed, defineAsyncComponent } from 'vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'

const { isRefreshing, pullDistance } = usePullToRefresh()

const ExerciseCategoryChart = defineAsyncComponent(() => import('@/Components/Stats/ExerciseCategoryChart.vue'))

const props = defineProps({
    /** Array of all exercises belonging to the user. */
    exercises: Array,
    /** List of available exercise categories (e.g., 'Pectoraux', 'Dos'). */
    categories: Array,
    /** List of available exercise types (e.g., 'strength', 'cardio'). */
    types: Array,
})

const showAddForm = ref(false)
const editingExercise = ref(null)
const searchQuery = ref('')
const activeCategory = ref('all')

// Local state for optimistic updates to ensure immediate UI feedback before server confirmation
const localExercises = ref([...props.exercises])

// Sync local state when the server returns updated props (e.g., after a successful partial reload)
import { watch } from 'vue'
watch(
    () => props.exercises,
    (newExercises) => {
        localExercises.value = [...newExercises]
    },
)

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

/**
 * Submit the create exercise form.
 * Triggers haptic feedback on success or error.
 */
const submit = () => {
    form.post(route('exercises.store'), {
        onSuccess: () => {
            form.reset()
            showAddForm.value = false
            triggerHaptic('success')
        },
        onError: () => triggerHaptic('error'),
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

/**
 * Optimistically delete an exercise.
 * Removes it from the local list immediately and restores it if the server request fails.
 */
const deleteExercise = (id) => {
    if (confirm('Supprimer cet exercice ?')) {
        const index = localExercises.value.findIndex((e) => e.id === id)
        if (index === -1) return

        const removed = localExercises.value[index]
        localExercises.value.splice(index, 1)
        triggerHaptic('warning')

        router.delete(route('exercises.destroy', { exercise: id }), {
            preserveScroll: true,
            onError: () => {
                // Rollback if server fails
                localExercises.value.splice(index, 0, removed)
                triggerHaptic('error')
            },
        })
    }
}

// Filter exercises based on the search query and selected category
const filteredExercises = computed(() => {
    return localExercises.value.filter((exercise) => {
        const matchesSearch =
            !searchQuery.value || exercise.name.toLowerCase().includes(searchQuery.value.toLowerCase())
        const matchesCategory = activeCategory.value === 'all' || exercise.category === activeCategory.value
        return matchesSearch && matchesCategory
    })
})

// Group filtered exercises by category for display
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
        <!-- Pull to Refresh Indicator -->
        <div
            class="pointer-events-none fixed top-0 left-0 z-50 flex w-full justify-center transition-transform duration-200 ease-out"
            :style="{ transform: `translateY(${Math.min(pullDistance, 150)}px)` }"
        >
            <div
                v-if="pullDistance > 0 || isRefreshing"
                class="mt-4 rounded-full border border-slate-200 bg-white/90 p-3 shadow-lg backdrop-blur-md dark:border-slate-700 dark:bg-slate-800/90"
            >
                <svg
                    v-if="isRefreshing"
                    class="text-electric-orange h-6 w-6 animate-spin"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                </svg>
                <span
                    v-else
                    class="material-symbols-outlined text-electric-orange transition-transform duration-200"
                    :style="{ transform: `rotate(${pullDistance > 100 ? 180 : 0}deg)` }"
                >
                    arrow_downward
                </span>
            </div>
        </div>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-5xl leading-none font-black tracking-tighter uppercase italic"
                    >
                        La<br />
                        <span class="text-gradient">Bibliothèque</span>
                    </h1>
                    <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                        {{ exercises.length }} exercices disponibles
                    </p>
                </div>
                <button
                    @click="showAddForm = true"
                    class="bg-gradient-main flex size-14 items-center justify-center rounded-2xl text-white shadow-lg shadow-orange-500/20 active:scale-95 sm:hidden"
                    data-testid="create-exercise-mobile-header"
                    aria-label="Nouvel exercice"
                >
                    <span class="material-symbols-outlined text-4xl" aria-hidden="true">add</span>
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
                        <h3 class="font-display text-text-main text-sm font-black tracking-wider uppercase">
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
                class="glass-panel-light animate-slide-up flex items-center gap-3 rounded-2xl p-3"
                style="animation-delay: 0.1s"
            >
                <span class="material-symbols-outlined text-text-muted text-[24px]">search</span>
                <input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Recherche exercices..."
                    class="text-text-main placeholder:text-text-muted/50 flex-1 border-none bg-transparent text-lg focus:ring-0 focus:outline-none"
                />
                <div
                    class="text-text-muted/40 hidden items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-[10px] font-bold tracking-widest uppercase sm:flex"
                >
                    <span class="material-symbols-outlined text-sm">keyboard</span>
                    ⌘K
                </div>
            </div>

            <!-- Category Pills -->
            <div class="hide-scrollbar animate-slide-up flex gap-2 overflow-x-auto pb-2" style="animation-delay: 0.15s">
                <button
                    @click="activeCategory = 'all'"
                    :class="[
                        'category-pill shrink-0 transition-all',
                        activeCategory === 'all'
                            ? 'bg-text-main text-white shadow-lg'
                            : 'text-text-main border border-slate-200 bg-white',
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
                        'category-pill shrink-0 transition-all',
                        activeCategory === cat
                            ? `${categoryColors[cat] || 'bg-slate-500'} text-white`
                            : 'text-text-main border border-slate-200 bg-white',
                    ]"
                >
                    {{ cat }}
                </button>
            </div>

            <!-- Add Form Modal -->
            <GlassCard v-if="showAddForm" class="animate-scale-in" variant="solid">
                <h3 class="font-display text-text-main mb-5 text-xl font-black uppercase">Nouvel exercice</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom de l'exercice"
                        placeholder="Ex: Développé couché"
                        :error="form.errors.name"
                    />
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Type</label>
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
                            <label class="font-display-label text-text-muted mb-2 block">Catégorie</label>
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
                <GlassEmptyState
                    title="Aucun exercice pour l'instant"
                    description="Ta bibliothèque est vide. Commence par créer ton premier exercice pour sculpter ton corps !"
                    icon="🏋️"
                    action-label="Créer le premier exercice"
                    @action="showAddForm = true"
                    color="green"
                    action-id="create-exercise-button"
                />
            </div>

            <!-- No Search Results -->
            <div v-else-if="filteredExercises.length === 0" class="animate-slide-up">
                <GlassEmptyState
                    :title="'Aucun résultat pour ' + searchQuery"
                    description="Essaie avec un autre mot-clé ou crée un nouvel exercice."
                    icon="search_off"
                    color="violet"
                >
                    <template #action>
                        <GlassButton variant="secondary" @click="searchQuery = ''"> Effacer la recherche </GlassButton>
                    </template>
                </GlassEmptyState>
            </div>

            <!-- Skeleton Loading -->
            <div v-if="!exercises" class="animate-pulse space-y-4">
                <GlassCard padding="p-4">
                    <div class="flex gap-4">
                        <GlassSkeleton width="60px" height="60px" borderRadius="16px" />
                        <div class="flex-1 space-y-3 py-1">
                            <GlassSkeleton width="70%" height="1.2rem" />
                            <GlassSkeleton width="40%" height="0.8rem" />
                        </div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="flex gap-4">
                        <GlassSkeleton width="60px" height="60px" borderRadius="16px" />
                        <div class="flex-1 space-y-3 py-1">
                            <GlassSkeleton width="60%" height="1.2rem" />
                            <GlassSkeleton width="50%" height="0.8rem" />
                        </div>
                    </div>
                </GlassCard>
            </div>

            <!-- Exercises List by Category -->
            <div v-else class="animate-slide-up space-y-8" style="animation-delay: 0.2s">
                <div v-for="(exercisesInCat, category) in groupedExercises" :key="category">
                    <div class="mb-3 flex items-center gap-2 px-1">
                        <h3 class="text-text-muted/60 text-[10px] font-black tracking-[0.25em] uppercase">
                            {{ category }}
                        </h3>
                        <div class="h-px flex-1 bg-slate-100"></div>
                        <span class="text-text-muted/30 text-[10px] font-black">
                            {{ exercisesInCat.length }}
                        </span>
                    </div>

                    <div class="space-y-3">
                        <SwipeableRow
                            v-for="exercise in exercisesInCat"
                            :key="exercise.id"
                            :disabled="editingExercise === exercise.id"
                            :action-threshold="80"
                            class="mb-3 block"
                        >
                            <template #action-left>
                                <button
                                    @click="startEdit(exercise)"
                                    class="flex h-full w-full items-center justify-start bg-blue-500 pl-6 text-white"
                                    data-testid="edit-exercise-button-mobile"
                                >
                                    <div class="flex flex-col items-center">
                                        <span class="material-symbols-outlined text-2xl">edit</span>
                                        <span class="text-[10px] font-bold tracking-wider uppercase">Modifier</span>
                                    </div>
                                </button>
                            </template>

                            <template #action-right>
                                <button
                                    @click="deleteExercise(exercise.id)"
                                    class="flex h-full w-full items-center justify-end bg-red-500 pr-6 text-white"
                                    data-testid="delete-exercise-button-mobile"
                                >
                                    <div class="flex flex-col items-center">
                                        <span class="material-symbols-outlined text-2xl">delete</span>
                                        <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                                    </div>
                                </button>
                            </template>

                            <GlassCard
                                padding="p-4"
                                :class="[
                                    'group relative overflow-hidden transition-all duration-300',
                                    'border-l-[6px]',
                                    categoryBorderColors[category] || 'border-l-slate-300',
                                ]"
                                data-testid="exercise-card"
                            >
                                <!-- View Mode -->
                                <div
                                    v-if="editingExercise !== exercise.id"
                                    class="flex cursor-pointer items-center justify-between"
                                    @click="router.visit(route('exercises.show', { exercise: exercise.id }))"
                                >
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
                                                class="font-display text-text-main text-lg leading-tight font-bold uppercase italic"
                                            >
                                                {{ exercise.name }}
                                            </div>
                                            <div
                                                class="text-text-muted mt-1 text-xs font-semibold tracking-wider uppercase"
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
                                            class="text-text-muted hover:bg-electric-orange/10 hover:text-electric-orange flex size-10 items-center justify-center rounded-xl transition-all sm:hidden"
                                            :aria-label="`Modifier ${exercise.name}`"
                                        >
                                            <span class="material-symbols-outlined text-sm opacity-50">edit</span>
                                        </button>

                                        <!-- Desktop Buttons -->
                                        <button
                                            @click="startEdit(exercise)"
                                            class="text-text-muted hover:bg-electric-orange/10 hover:text-electric-orange hidden size-10 items-center justify-center rounded-xl transition-all sm:flex"
                                            data-testid="edit-exercise-button"
                                            :aria-label="`Modifier ${exercise.name}`"
                                        >
                                            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                                        </button>
                                        <button
                                            @click="deleteExercise(exercise.id)"
                                            class="text-text-muted hidden size-10 items-center justify-center rounded-xl transition-all hover:bg-red-50 hover:text-red-500 sm:flex"
                                            data-testid="delete-exercise-button"
                                            :aria-label="`Supprimer ${exercise.name}`"
                                        >
                                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
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
                        </SwipeableRow>
                    </div>
                </div>
            </div>
            <!-- List Padding for Mobile Bottom Nav -->
            <div class="h-24 sm:hidden"></div>
        </div>
    </AuthenticatedLayout>
</template>
