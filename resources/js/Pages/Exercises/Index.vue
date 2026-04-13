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
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed, defineAsyncComponent, onMounted, onUnmounted, watch } from 'vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import Modal from '@/Components/UI/Modal.vue'
import ExerciseCard from '@/Components/Workout/ExerciseCard.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import {
    EXERCISE_CATEGORIES,
    EXERCISE_TYPES,
    CATEGORY_COLORS,
    CATEGORY_BORDER_COLORS,
    TYPE_ICONS,
} from '@/Utils/constants'

const { isRefreshing, pullDistance } = usePullToRefresh()

const ExerciseCategoryChart = defineAsyncComponent(() => import('@/Components/Stats/ExerciseCategoryChart.vue'))

const props = defineProps({
    /** Array of all exercises belonging to the user. */
    exercises: Array,
})

const showAddForm = ref(false)
const editingExercise = ref(null)
const searchQuery = ref('')
const activeCategory = ref(localStorage.getItem('gymtracker_active_category') || 'all')
const searchInput = ref(null)

watch(activeCategory, (newCat) => {
    localStorage.setItem('gymtracker_active_category', newCat)
})

const handleKeyDown = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        searchInput.value?.focus()
    }

    if (e.key === 'Escape' && document.activeElement === searchInput.value) {
        searchInput.value?.blur()
        searchQuery.value = ''
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyDown)
})

// Local state for optimistic updates to ensure immediate UI feedback before server confirmation
const localExercises = ref([...props.exercises])

// Sync local state when the server returns updated props (e.g., after a successful partial reload)
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

const typeLabel = (type) => {
    if (!EXERCISE_TYPES) return type
    const found = EXERCISE_TYPES.find((t) => t.value === type)
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
        <div class="pb-main-safe space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-3xl leading-none font-black tracking-tighter uppercase italic sm:text-5xl"
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
                    dusk="create-exercise-btn"
                    aria-label="Nouvel exercice"
                >
                    <span class="material-symbols-outlined text-4xl" aria-hidden="true">add</span>
                </button>
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    class="hidden sm:flex"
                    data-testid="create-exercise-desktop"
                    dusk="create-exercise-btn-desktop"
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
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <GlassInput
                    id="search-exercises-input"
                    ref="searchInput"
                    v-model="searchQuery"
                    type="search"
                    size="lg"
                    label="Rechercher des exercices"
                    hide-label
                    dusk="search-exercises"
                    placeholder="Recherche exercices..."
                    :aria-label="'Rechercher des exercices (Raccourci : ⌘K)'"
                >
                    <template #suffix>
                        <div
                            class="text-text-muted/40 hidden items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-[10px] font-bold tracking-widest uppercase sm:flex"
                            aria-hidden="true"
                        >
                            <span class="material-symbols-outlined text-sm">keyboard</span>
                            ⌘K
                        </div>
                    </template>
                </GlassInput>
            </div>

            <!-- Category Pills -->
            <div class="hide-scrollbar animate-slide-up flex gap-2 overflow-x-auto pb-2" style="animation-delay: 0.15s">
                <button
                    v-press="{ haptic: 'selection' }"
                    @click="activeCategory = 'all'"
                    dusk="category-pill-all"
                    :class="[
                        'category-pill shrink-0 transition-all',
                        activeCategory === 'all'
                            ? 'bg-text-main text-white shadow-lg'
                            : 'text-text-main border border-slate-200 bg-white',
                    ]"
                    :aria-pressed="activeCategory === 'all'"
                >
                    <span class="material-symbols-outlined text-lg">apps</span>
                    Tous
                </button>
                <button
                    v-for="cat in EXERCISE_CATEGORIES"
                    :key="cat"
                    v-press="{ haptic: 'selection' }"
                    @click="activeCategory = cat"
                    :dusk="`category-pill-${cat}`"
                    :class="[
                        'category-pill shrink-0 transition-all',
                        activeCategory === cat
                            ? `${CATEGORY_COLORS[cat] || 'bg-slate-500'} text-white`
                            : 'text-text-main border border-slate-200 bg-white',
                    ]"
                    :aria-pressed="activeCategory === cat"
                >
                    {{ cat }}
                </button>
            </div>

            <!-- Add Form Modal -->
            <Modal :show="showAddForm" @close="showAddForm = false" max-width="sm">
                <div class="p-6">
                    <h3
                        class="font-display text-text-main mb-5 text-xl font-black uppercase"
                        dusk="exercise-modal-title"
                    >
                        Nouvel exercice
                    </h3>
                    <form @submit.prevent="submit" class="space-y-4">
                        <GlassInput
                            v-model="form.name"
                            name="name"
                            dusk="exercise-name-input"
                            label="Nom de l'exercice"
                            placeholder="Ex: Développé couché"
                            :error="form.errors.name"
                        />
                        <div class="grid grid-cols-2 gap-4">
                            <GlassSelect
                                v-model="form.type"
                                label="Type"
                                :options="EXERCISE_TYPES"
                                :error="form.errors.type"
                                placeholder=""
                            />
                            <GlassSelect
                                v-model="form.category"
                                label="Catégorie"
                                :options="[
                                    { value: '', label: '— Aucune —' },
                                    ...EXERCISE_CATEGORIES.map((c) => ({ value: c, label: c })),
                                ]"
                                placeholder=""
                            />
                        </div>
                        <GlassButton
                            type="submit"
                            variant="primary"
                            class="w-full"
                            :loading="form.processing"
                            data-testid="submit-exercise-button"
                            dusk="submit-exercise-btn"
                        >
                            Créer l'exercice
                        </GlassButton>
                    </form>
                </div>
            </Modal>

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
                        <ExerciseCard
                            v-for="exercise in exercisesInCat"
                            :key="exercise.id"
                            :exercise="exercise"
                            :is-editing="editingExercise === exercise.id"
                            :edit-form="editForm"
                            :category="category"
                            :types="EXERCISE_TYPES"
                            :categories="EXERCISE_CATEGORIES"
                            :category-border-colors="CATEGORY_BORDER_COLORS"
                            :type-icons="TYPE_ICONS"
                            :type-label="typeLabel"
                            @start-edit="startEdit"
                            @cancel-edit="cancelEdit"
                            @update="updateExercise"
                            @delete="deleteExercise"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
