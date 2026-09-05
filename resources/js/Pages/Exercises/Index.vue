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
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import Modal from '@/Components/UI/Modal.vue'
import ExerciseCard from '@/Components/Workout/ExerciseCard.vue'
import IndicateurDeRafraichissement from '@/Components/UI/IndicateurDeRafraichissement.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
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
const searchInput = ref(null)

/**
 * The filter lives in the URL, not in localStorage.
 *
 * Stored in localStorage it outlived the reason it was set: filter to "Jambes"
 * on leg day and the library still opens filtered weeks later, looking like
 * most of the exercises have disappeared — with no clue why if the category
 * chips have scrolled out of view. A filter chosen for one session was being
 * replayed as a permanent preference.
 *
 * In the URL it behaves the way a filter is expected to: it survives a reload,
 * it can be linked to, the back button steps out of it, and opening the library
 * fresh from the nav shows everything.
 */
const CATEGORY_PARAM = 'category'

const readCategoryFromUrl = () => new URLSearchParams(window.location.search).get(CATEGORY_PARAM) || 'all'

const activeCategory = ref(readCategoryFromUrl())

watch(activeCategory, (category) => {
    const url = new URL(window.location.href)

    if (category === 'all') {
        url.searchParams.delete(CATEGORY_PARAM)
    } else {
        url.searchParams.set(CATEGORY_PARAM, category)
    }

    // The filtering is done client-side, so this must not become an Inertia
    // visit. Passing the current history state back keeps Inertia's own
    // back/forward restoration intact.
    window.history.replaceState(window.history.state, '', url)
})

const handleKeyDown = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        searchInput.value?.focus()
    }

    // `searchInput` porte le proxy exposé par GlassInput, pas son élément
    // racine : la comparaison portait donc sur un objet JS et n'était jamais
    // vraie, quel que soit l'état du focus. Le champ concerné se reconnaît par
    // l'input que le composant expose.
    if (e.key === 'Escape' && document.activeElement === searchInput.value?.el) {
        searchInput.value.blur()
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
const {
    cible: exerciceASupprimer,
    ouvert: suppressionDemandee,
    demander: retenirSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((exercice, termine) => {
    /*
     * On referme le dialogue AVANT de retirer la ligne : la mise à jour est
     * optimiste, et la modale resterait sinon ouverte sur une liste qui a déjà
     * bougé sous elle. La ligne revient à sa place exacte si le serveur refuse.
     */
    termine()

    const index = localExercises.value.findIndex((e) => e.id === exercice.id)

    if (index === -1) {
        return
    }

    const removed = localExercises.value[index]
    localExercises.value.splice(index, 1)
    triggerHaptic('warning')

    router.delete(route('exercises.destroy', { exercise: exercice.id }), {
        preserveScroll: true,
        onError: () => {
            localExercises.value.splice(index, 0, removed)
            triggerHaptic('error')
        },
    })
})

/*
 * L'enfant n'émet qu'un identifiant : on retrouve l'exercice pour le NOMMER
 * dans la question. « Supprimer cet exercice ? » ne disait pas lequel.
 */
const demanderSuppression = (id) => {
    retenirSuppression(localExercises.value.find((exercice) => exercice.id === id) ?? { id })
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

/**
 * La bibliotheque est vide, ce qui n'est pas la meme chose qu'un filtre sans
 * resultat.
 *
 * L'etat vide « general » — celui qui invite a creer son premier exercice —
 * etait montre des que la liste filtree etait vide sans recherche textuelle.
 * Filtrer sur une categorie ou l'utilisateur n'a rien affichait donc « ta
 * bibliotheque est vide » a quelqu'un qui possede des dizaines d'exercices,
 * sans lui laisser deviner que c'est le filtre qui ne rend rien (#1389).
 *
 * La question porte maintenant sur la bibliotheque elle-meme, pas sur ce que le
 * filtre en laisse voir.
 */
const libraryIsEmpty = computed(() => localExercises.value.length === 0)

/** Ce que l'utilisateur peut relacher pour revoir quelque chose. */
const hasActiveFilter = computed(() => searchQuery.value !== '' || activeCategory.value !== 'all')

const clearFilters = () => {
    searchQuery.value = ''
    activeCategory.value = 'all'
}

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
    const found = EXERCISE_TYPES.find((t) => t.value === type)
    return found ? found.label : type
}
</script>

<template>
    <Head title="Bibliothèque" />

    <AuthenticatedLayout liquid-variant="subtle" page-title="La Bibliothèque">
        <IndicateurDeRafraichissement :distance="pullDistance" :en-cours="isRefreshing" />
        <div class="pb-main-safe space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main hidden text-3xl leading-none font-black tracking-tighter uppercase italic sm:block sm:text-5xl"
                    >
                        La<br />
                        <span class="text-gradient">Bibliothèque</span>
                    </h1>
                    <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                        {{ exercises.length }} exercices disponibles
                    </p>
                </div>
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    class="hidden sm:flex"
                    data-testid="create-exercise-desktop"
                    dusk="create-exercise-btn-desktop"
                >
                    <span class="material-symbols-outlined mr-2" aria-hidden="true">add</span>
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
                            class="text-text-muted/40 border-border hidden items-center gap-1 rounded-lg border px-2 py-1 text-[10px] font-bold tracking-widest uppercase sm:flex"
                            aria-hidden="true"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">keyboard</span>
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
                            ? 'bg-text-main text-surface-card shadow-lg'
                            : 'text-text-main border-border bg-surface-card border',
                    ]"
                    :aria-pressed="activeCategory === 'all'"
                >
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">apps</span>
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
                            ? (CATEGORY_COLORS[cat] ?? 'category-fill-other')
                            : 'text-text-main border-border bg-surface-card border',
                    ]"
                    :aria-pressed="activeCategory === cat"
                >
                    {{ cat }}
                </button>
            </div>

            <!-- Add Form Modal -->
            <Modal :show="showAddForm" @close="showAddForm = false" max-width="sm" aria-labelledby="new-exercise-title">
                <div class="p-6">
                    <h3
                        id="new-exercise-title"
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
                                name="type"
                                label="Type"
                                :options="EXERCISE_TYPES"
                                :error="form.errors.type"
                                placeholder=""
                            />
                            <GlassSelect
                                v-model="form.category"
                                name="category"
                                label="Catégorie"
                                :options="EXERCISE_CATEGORIES.map((c) => ({ value: c, label: c }))"
                                empty-label="— Aucune —"
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
            <GlassCard v-if="$page.props.errors?.exercise" class="border-accent-danger bg-accent-danger/10">
                <p class="text-accent-danger-deep text-center font-bold">{{ $page.props.errors.exercise }}</p>
            </GlassCard>

            <!-- Empty State -->
            <div v-if="libraryIsEmpty" class="animate-slide-up">
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

            <!-- Nothing matches the filters -->
            <div v-else-if="filteredExercises.length === 0 && hasActiveFilter" class="animate-slide-up">
                <!--
                    Le titre citait la recherche sans condition et se lisait
                    « Aucun résultat pour » suivi de rien quand seule la
                    catégorie filtrait — le cas que cet état ne savait pas voir.
                -->
                <GlassEmptyState
                    :title="searchQuery ? 'Aucun résultat pour ' + searchQuery : 'Aucun exercice dans cette catégorie'"
                    description="Essaie un autre filtre, ou crée un nouvel exercice."
                    icon="search_off"
                    color="violet"
                >
                    <template #action>
                        <GlassButton variant="secondary" @click="clearFilters"> Réinitialiser les filtres </GlassButton>
                    </template>
                </GlassEmptyState>
            </div>

            <!-- Skeleton Loading -->
            <div v-if="!exercises" class="animate-pulse space-y-4">
                <GlassCard padding="p-4">
                    <div class="flex gap-4">
                        <GlassSkeleton width="60px" height="60px" class="rounded-2xl" />
                        <div class="flex-1 space-y-3 py-1">
                            <GlassSkeleton width="70%" height="1.2rem" />
                            <GlassSkeleton width="40%" height="0.8rem" />
                        </div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="flex gap-4">
                        <GlassSkeleton width="60px" height="60px" class="rounded-2xl" />
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
                        <div class="bg-surface-sunken h-px flex-1"></div>
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
                            @delete="demanderSuppression"
                        />
                    </div>
                </div>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cet exercice ?"
            :description="
                exerciceASupprimer?.name ? `« ${exerciceASupprimer.name} » sera retiré de ta bibliothèque.` : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />

        <!--
            Le « + » de la barre collante. Il vivait dans le corps de la page,
            en `sm:hidden`, à côté de son jumeau de bureau — donc sous
            l'en-tête plutôt que dedans, et pas au même endroit que celui de
            « Mes Séances » ou de « Mes Modèles ».
        -->
        <template #header-actions>
            <GlassButton
                @click="showAddForm = true"
                variant="primary"
                class="min-h-touch! flex h-11! w-11! items-center justify-center p-0!"
                data-testid="create-exercise-mobile-header"
                dusk="create-exercise-btn"
                aria-label="Nouvel exercice"
            >
                <span class="material-symbols-outlined text-xl leading-none" aria-hidden="true">add</span>
            </GlassButton>
        </template>
    </AuthenticatedLayout>
</template>
