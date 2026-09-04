<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import Modal from '@/Components/UI/Modal.vue'
import { useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    // Absent à la création, présent à la modification : c'est la seule
    // chose qui distingue les deux pages.
    template: {
        type: Object,
        default: null,
    },
    exercises: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
    },
    types: {
        type: Array,
        default: () => [
            { value: 'strength', label: 'Force' },
            { value: 'cardio', label: 'Cardio' },
            { value: 'timed', label: 'Temps' },
        ],
    },
})

// Clef de rendu stable : sans elle, `:key` par index fait suivre le focus
// au rang plutot qu'a l'exercice deplace.
let nextUid = 0

const initialExercises = (props.template?.workout_template_lines || []).map((line) => ({
    uid: nextUid++,
    id: line.exercise_id,
    name: line.exercise.name,
    sets: (line.workout_template_sets || []).map((set) => ({
        reps: set.reps,
        weight: set.weight,
        is_warmup: set.is_warmup,
    })),
}))

const form = useForm({
    name: props.template?.name ?? '',
    description: props.template?.description || '',
    exercises: initialExercises,
})

const idDescription = `template-description-${props.template?.id ?? 'new'}`

const searchQuery = ref('')
const showAddExercise = ref(false)
const showCreateForm = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))

const createExerciseForm = useForm({
    name: '',
    type: 'strength',
    category: '',
})

// Quick-create goes through fetch() rather than Inertia, so nothing routes a
// non-422 failure anywhere. This is where it lands.
const createError = ref(null)

const filteredExercises = computed(() => {
    const exercises = localExercises.value
    if (!searchQuery.value) return exercises
    return exercises.filter((e) => e.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
})

const hasNoResults = computed(() => {
    return searchQuery.value && filteredExercises.value.length === 0
})

const addExercise = (exercise) => {
    form.exercises.push({
        uid: nextUid++,
        id: exercise.id,
        name: exercise.name,
        sets: [{ reps: 10, weight: null, is_warmup: false }],
    })
    showAddExercise.value = false
    searchQuery.value = ''
}

const createAndAddExercise = async () => {
    createExerciseForm.processing = true
    createExerciseForm.clearErrors()
    createError.value = null

    try {
        const response = await fetch(route('exercises.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Quick-Create': 'true',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: createExerciseForm.name,
                type: createExerciseForm.type,
                category: createExerciseForm.category,
            }),
        })

        if (response.ok) {
            const responseData = await response.json()
            const exercise = responseData.exercise || responseData.data || responseData

            if (!exercise || !exercise.id) {
                createError.value = "La réponse du serveur n'a pas pu être lue. L'exercice n'a pas été créé."
                createExerciseForm.processing = false
                return
            }

            localExercises.value.push(exercise)
            localExercises.value.sort((a, b) => a.name.localeCompare(b.name))

            addExercise(exercise)

            createExerciseForm.reset()
            showCreateForm.value = false
            createExerciseForm.processing = false
        } else if (response.status === 422) {
            const errors = await response.json()
            if (errors.errors) {
                Object.keys(errors.errors).forEach((key) => {
                    createExerciseForm.setError(key, errors.errors[key][0])
                })
            }
            createExerciseForm.processing = false
        } else {
            // 419 on an expired CSRF token, 403, 500, anything else. Resetting
            // `processing` and stopping there just un-spun the button: the user
            // pressed "Créer et ajouter" and the modal sat unchanged.
            createError.value = `La création a échoué (erreur ${response.status}). Réessaie.`
            createExerciseForm.processing = false
        }
    } catch {
        createError.value = 'La création a échoué. Vérifie ta connexion et réessaie.'
        createExerciseForm.processing = false
    }
}

const quickCreate = () => {
    createExerciseForm.name = searchQuery.value
    showCreateForm.value = true
}

const closeAddModal = () => {
    showAddExercise.value = false
    showCreateForm.value = false
    searchQuery.value = ''
}

const addSet = (exerciseIndex) => {
    form.exercises[exerciseIndex].sets.push({
        reps: 10,
        weight: null,
        is_warmup: false,
    })
}

const removeSet = (exerciseIndex, setIndex) => {
    form.exercises[exerciseIndex].sets.splice(setIndex, 1)
}

const moveExercise = (index, delta) => {
    const target = index + delta

    if (target < 0 || target >= form.exercises.length) {
        return
    }

    const [moved] = form.exercises.splice(index, 1)
    form.exercises.splice(target, 0, moved)
}

const removeExercise = (index) => {
    form.exercises.splice(index, 1)
}

const submit = () => {
    if (props.template) {
        form.put(route('templates.update', { template: props.template.id }))
        return
    }
    form.post(route('templates.store'))
}
</script>

<template>
    <div>
        <form @submit.prevent="submit" class="space-y-6">
            <GlassCard class="animate-slide-up">
                <div class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom du modèle"
                        placeholder="ex: Full Body Lundi"
                        :error="form.errors.name"
                        required
                    />

                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <label :for="idDescription" class="text-text-muted block text-sm font-medium"
                                >Description (optionnel)</label
                            >
                            <span
                                :id="`${idDescription}-counter`"
                                class="text-[10px] font-bold tracking-wider uppercase"
                                :class="
                                    form.description?.length > 1000 ? 'text-accent-danger-deep' : 'text-text-muted/50'
                                "
                            >
                                {{ form.description?.length || 0 }} / 1000
                            </span>
                        </div>
                        <textarea
                            :id="idDescription"
                            v-model="form.description"
                            rows="2"
                            maxlength="1000"
                            :aria-describedby="`${idDescription}-counter`"
                            class="text-text-main placeholder:text-text-muted/50 border-surface-card/20 bg-surface-card/10 hover:border-surface-card/30 hover:bg-surface-card/15 focus:border-surface-card/50 focus:bg-surface-card/20 w-full rounded-2xl border px-4 py-3 backdrop-blur-md transition-all duration-300 focus:shadow-[0_0_15px_rgb(from_var(--color-surface-card)_r_g_b_/_0.1)] focus:ring-0 focus:outline-none"
                            placeholder="Détails de la séance..."
                        ></textarea>
                        <p v-if="form.errors.description" class="text-accent-danger-deep mt-2 text-sm font-medium">
                            {{ form.errors.description }}
                        </p>
                    </div>
                </div>
            </GlassCard>

            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="text-text-main mb-3 font-semibold">Exercices</h3>

                <div class="space-y-4">
                    <div v-for="(exercise, exIndex) in form.exercises" :key="exercise.uid">
                        <GlassCard>
                            <div class="mb-4 flex items-start justify-between gap-2">
                                <h4 class="text-text-main min-w-0 text-lg font-bold">{{ exercise.name }}</h4>

                                <div class="flex shrink-0 items-center gap-1">
                                    <GlassIconButton
                                        v-press
                                        icon="arrow_upward"
                                        :label="`Monter ${exercise.name}`"
                                        :disabled="exIndex === 0"
                                        @click="moveExercise(exIndex, -1)"
                                    />
                                    <GlassIconButton
                                        v-press
                                        icon="arrow_downward"
                                        :label="`Descendre ${exercise.name}`"
                                        :disabled="exIndex === form.exercises.length - 1"
                                        @click="moveExercise(exIndex, 1)"
                                    />
                                    <GlassIconButton
                                        v-press
                                        icon="close"
                                        :label="`Supprimer ${exercise.name}`"
                                        ton="danger"
                                        @click="removeExercise(exIndex)"
                                    />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(set, setIndex) in exercise.sets"
                                    :key="setIndex"
                                    class="flex items-center gap-2"
                                >
                                    <div
                                        class="text-text-muted bg-surface-sunken flex h-8 w-8 items-center justify-center rounded-lg text-xs font-bold"
                                    >
                                        {{ setIndex + 1 }}
                                    </div>
                                    <input
                                        v-model="set.reps"
                                        type="number"
                                        class="text-text-main placeholder:text-text-muted/40 border-border bg-surface-card/50 h-10 w-20 rounded-lg border text-center text-base"
                                        placeholder="réps"
                                    />
                                    <input
                                        v-model="set.weight"
                                        type="number"
                                        step="0.5"
                                        class="text-text-main placeholder:text-text-muted/40 border-border bg-surface-card/50 h-10 w-20 rounded-lg border text-center text-base"
                                        placeholder="kg"
                                    />
                                    <button
                                        v-press="{ haptic: 'selection' }"
                                        @click="set.is_warmup = !set.is_warmup"
                                        type="button"
                                        class="focus-visible:ring-accent-primary h-10 rounded-lg px-2 py-1 text-[10px] font-bold transition focus-visible:ring-2 focus-visible:outline-none"
                                        :class="
                                            set.is_warmup
                                                ? 'bg-accent-primary/20 text-accent-primary-deep'
                                                : 'text-text-muted/50 bg-surface-sunken'
                                        "
                                        aria-label="Série d'échauffement"
                                        :aria-pressed="set.is_warmup"
                                    >
                                        W
                                    </button>
                                    <GlassIconButton
                                        v-press
                                        icon="delete"
                                        label="Supprimer la série"
                                        ton="danger"
                                        compact
                                        class="ml-auto"
                                        @click="removeSet(exIndex, setIndex)"
                                    />
                                </div>
                                <button
                                    v-press
                                    @click="addSet(exIndex)"
                                    type="button"
                                    class="text-accent-primary-deep focus-visible:ring-accent-primary rounded-lg text-xs transition-all hover:underline focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    + Ajouter une série
                                </button>
                            </div>
                        </GlassCard>
                    </div>

                    <GlassButton
                        @click="showAddExercise = true"
                        type="button"
                        variant="primary"
                        class="w-full"
                        dusk="open-add-exercise"
                    >
                        + Ajouter un exercice
                    </GlassButton>
                </div>
            </div>

            <div class="animate-slide-up pt-6" style="animation-delay: 0.2s">
                <GlassButton variant="primary" size="lg" class="w-full" :loading="form.processing" type="submit">
                    {{ template ? 'Mettre à jour le modèle' : 'Enregistrer le modèle' }}
                </GlassButton>
            </div>
        </form>

        <!-- Add Exercise Modal. Built out of bare divs it had no dialog role, no
             Escape, and nothing keeping Tab from wandering into the form behind
             it; Modal opens a native <dialog>, which supplies all three. -->
        <Modal
            :show="showAddExercise"
            max-width="lg"
            position="bottom"
            aria-labelledby="add-exercise-title"
            @close="closeAddModal"
        >
            <div>
                <!-- Modal Header -->
                <div class="border-border flex items-center justify-between border-b p-4">
                    <h3 id="add-exercise-title" class="font-display text-text-main text-lg font-black uppercase italic">
                        {{ showCreateForm ? 'Nouvel exercice' : 'Choisir un exercice' }}
                    </h3>
                    <GlassIconButton v-press icon="close" label="Fermer" @click="closeAddModal" />
                </div>

                <!-- Create Exercise Form -->
                <div v-if="showCreateForm" class="p-4">
                    <form @submit.prevent="createAndAddExercise" class="space-y-4">
                        <GlassInput
                            v-model="createExerciseForm.name"
                            label="Nom de l'exercice"
                            placeholder="Ex: Développé couché"
                            :error="createExerciseForm.errors.name"
                            autofocus
                        />
                        <div class="grid grid-cols-2 gap-3">
                            <GlassSelect v-model="createExerciseForm.type" label="Type" :options="types" />
                            <GlassSelect
                                v-model="createExerciseForm.category"
                                label="Catégorie"
                                :options="categories"
                                empty-label="— Aucune —"
                            />
                        </div>
                        <p
                            v-if="createError"
                            class="text-accent-danger-deep text-sm font-bold"
                            role="alert"
                            dusk="quick-create-error"
                        >
                            {{ createError }}
                        </p>

                        <div class="flex gap-2">
                            <GlassButton
                                type="submit"
                                variant="primary"
                                class="flex-1"
                                :loading="createExerciseForm.processing"
                            >
                                Créer et ajouter
                            </GlassButton>
                            <GlassButton type="button" variant="secondary" @click="showCreateForm = false">
                                Annuler
                            </GlassButton>
                        </div>
                    </form>
                </div>

                <!-- Search & List -->
                <template v-else>
                    <div class="p-4 uppercase">
                        <GlassInput v-model="searchQuery" placeholder="Rechercher..." autofocus />
                    </div>

                    <div class="max-h-[50vh] overflow-y-auto p-4 pt-0">
                        <!-- No Results - Quick Create -->
                        <div v-if="hasNoResults" class="py-6 text-center">
                            <p class="text-text-muted mb-3">Aucun exercice trouvé pour "{{ searchQuery }}"</p>
                            <GlassButton variant="primary" type="button" @click="quickCreate">
                                Créer "{{ searchQuery }}"
                            </GlassButton>
                        </div>

                        <!-- Exercise List -->
                        <div v-else class="space-y-2">
                            <button
                                v-for="ex in filteredExercises"
                                :key="ex.id"
                                type="button"
                                @click="addExercise(ex)"
                                class="hover:border-accent-primary border-border bg-surface-sunken hover:bg-surface-card flex w-full items-center justify-between rounded-2xl border p-4 transition"
                            >
                                <div class="text-left">
                                    <div class="text-text-main font-bold">{{ ex.name }}</div>
                                    <div class="text-text-muted text-xs">{{ ex.category }}</div>
                                </div>
                                <span class="material-symbols-outlined text-accent-primary-deep" aria-hidden="true"
                                    >add_circle</span
                                >
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </Modal>
    </div>
</template>
