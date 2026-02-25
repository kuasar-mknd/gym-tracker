<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
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

const form = useForm({
    name: '',
    description: '',
    exercises: [],
})

const searchQuery = ref('')
const showAddExercise = ref(false)
const showCreateForm = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))

const createExerciseForm = useForm({
    name: '',
    type: 'strength',
    category: '',
})

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
            createExerciseForm.processing = false
        }
    } catch (error) {
        console.error('Error creating exercise:', error)
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

const removeExercise = (index) => {
    form.exercises.splice(index, 1)
}

const submit = () => {
    form.post(route('templates.store'))
}
</script>

<template>
    <Head title="Nouveau Modèle" />

    <AuthenticatedLayout page-title="Nouveau Modèle" show-back back-route="templates.index">
        <form @submit.prevent="submit" class="space-y-6">
            <GlassCard class="animate-slide-up">
                <div class="space-y-4">
                    <div>
                        <label class="text-text-muted block text-sm font-medium">Nom du modèle</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="text-text-main focus:ring-accent-primary mt-1 w-full rounded-xl border border-slate-200 bg-white/50 focus:ring-2"
                            placeholder="ex: Full Body Lundi"
                        />
                        <div v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</div>
                    </div>

                    <div>
                        <label class="text-text-muted block text-sm font-medium">Description (optionnel)</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            class="text-text-main focus:ring-accent-primary mt-1 w-full rounded-xl border border-slate-200 bg-white/50 focus:ring-2"
                            placeholder="Détails de la séance..."
                        ></textarea>
                    </div>
                </div>
            </GlassCard>

            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="text-text-main mb-3 font-semibold">Exercices</h3>

                <div class="space-y-4">
                    <div v-for="(exercise, exIndex) in form.exercises" :key="exIndex">
                        <GlassCard class="relative">
                            <button
                                @click="removeExercise(exIndex)"
                                type="button"
                                class="text-text-muted/30 absolute top-4 right-4 hover:text-red-400"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>

                            <div class="mb-4">
                                <h4 class="text-text-main text-lg font-bold">{{ exercise.name }}</h4>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(set, setIndex) in exercise.sets"
                                    :key="setIndex"
                                    class="flex items-center gap-2"
                                >
                                    <div
                                        class="text-text-muted flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold"
                                    >
                                        {{ setIndex + 1 }}
                                    </div>
                                    <input
                                        v-model="set.reps"
                                        type="number"
                                        class="text-text-main placeholder:text-text-muted/40 h-10 w-20 rounded-lg border border-slate-200 bg-white/50 text-center text-sm"
                                        placeholder="reps"
                                    />
                                    <input
                                        v-model="set.weight"
                                        type="number"
                                        step="0.5"
                                        class="text-text-main placeholder:text-text-muted/40 h-10 w-20 rounded-lg border border-slate-200 bg-white/50 text-center text-sm"
                                        placeholder="kg"
                                    />
                                    <button
                                        @click="set.is_warmup = !set.is_warmup"
                                        type="button"
                                        class="h-10 rounded-lg px-2 py-1 text-[10px] font-bold transition"
                                        :class="
                                            set.is_warmup
                                                ? 'bg-orange-500/20 text-orange-400'
                                                : 'text-text-muted/50 bg-slate-100'
                                        "
                                    >
                                        W
                                    </button>
                                    <button
                                        @click="removeSet(exIndex, setIndex)"
                                        type="button"
                                        class="text-text-muted/20 ml-auto p-1 hover:text-red-400"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <button
                                    @click="addSet(exIndex)"
                                    type="button"
                                    class="text-accent-primary text-xs hover:underline"
                                >
                                    + Ajouter une série
                                </button>
                            </div>
                        </GlassCard>
                    </div>

                    <GlassButton @click="showAddExercise = true" type="button" class="w-full">
                        + Ajouter un exercice
                    </GlassButton>
                </div>
            </div>

            <div class="animate-slide-up pt-6" style="animation-delay: 0.2s">
                <GlassButton variant="primary" size="lg" class="w-full" :loading="form.processing" type="submit">
                    Enregistrer le modèle
                </GlassButton>
            </div>
        </form>

        <!-- Add Exercise Modal -->
        <Teleport to="body">
            <div v-if="showAddExercise" class="glass-overlay animate-fade-in" @click.self="closeAddModal">
                <div
                    class="fixed inset-x-4 top-auto bottom-4 max-h-[80vh] sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2"
                >
                    <div class="glass-modal animate-slide-up overflow-hidden">
                        <!-- Modal Header -->
                        <div class="flex items-center justify-between border-b border-slate-200 p-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                {{ showCreateForm ? 'Nouvel exercice' : 'Choisir un exercice' }}
                            </h3>
                            <button
                                @click="closeAddModal"
                                type="button"
                                class="text-text-muted hover:text-text-main rounded-xl p-2 hover:bg-slate-100"
                                aria-label="Fermer"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
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
                                    <div>
                                        <label
                                            class="text-text-muted mb-2 block text-xs font-black tracking-widest uppercase"
                                            >Type</label
                                        >
                                        <select v-model="createExerciseForm.type" class="glass-input w-full text-sm">
                                            <option v-for="t in types" :key="t.value" :value="t.value">
                                                {{ t.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label
                                            class="text-text-muted mb-2 block text-xs font-black tracking-widest uppercase"
                                            >Catégorie</label
                                        >
                                        <select
                                            v-model="createExerciseForm.category"
                                            class="glass-input w-full text-sm"
                                        >
                                            <option value="">— Aucune —</option>
                                            <option v-for="cat in categories" :key="cat" :value="cat">
                                                {{ cat }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <GlassButton
                                        type="submit"
                                        variant="primary"
                                        class="flex-1"
                                        :loading="createExerciseForm.processing"
                                    >
                                        Créer et ajouter
                                    </GlassButton>
                                    <GlassButton type="button" variant="ghost" @click="showCreateForm = false">
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
                                        class="hover:border-accent-primary flex w-full items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 p-4 transition hover:bg-white"
                                    >
                                        <div class="text-left">
                                            <div class="text-text-main font-bold">{{ ex.name }}</div>
                                            <div class="text-text-muted text-xs">{{ ex.category }}</div>
                                        </div>
                                        <span class="material-symbols-outlined text-accent-primary">add_circle</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
