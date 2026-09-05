<script setup>
/**
 * Choisir un exercice de la bibliothèque, ou en créer un sur le champ.
 *
 * La recherche est gardée dans le stockage local : on revient souvent chercher
 * le même exercice d'une séance à l'autre. Un nom sans résultat propose de le
 * créer ; la création parle au serveur elle-même et rend l'exercice au parent,
 * qui l'ajoute à sa liste puis à la séance.
 */
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import { EXERCISE_CATEGORIES, EXERCISE_TYPES } from '@/Utils/constants'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    show: { type: Boolean, default: false },
    exercises: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'add', 'created'])

const CLEF_DE_RECHERCHE = 'gymtracker_add_exercise_search'

const searchQuery = ref(localStorage.getItem(CLEF_DE_RECHERCHE) || '')
watch(searchQuery, (valeur) => {
    localStorage.setItem(CLEF_DE_RECHERCHE, valeur)
})

const showCreateForm = ref(false)
const createExerciseForm = useForm({ name: '', type: 'strength', category: 'Pectoraux' })

const filteredExercises = computed(() => {
    const q = searchQuery.value.toLowerCase().trim()

    return q ? props.exercises.filter((e) => e.name.toLowerCase().includes(q)) : props.exercises
})

const choisir = (exerciseId) => {
    emit('add', exerciseId)
    searchQuery.value = ''
}

const quickCreate = () => {
    createExerciseForm.name = searchQuery.value
    showCreateForm.value = true
}

const fermer = () => {
    showCreateForm.value = false
    searchQuery.value = ''
    emit('close')
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
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: createExerciseForm.name,
                type: createExerciseForm.type,
                category: createExerciseForm.category,
            }),
        })
        if (response.ok) {
            const { exercise } = await response.json()

            emit('created', exercise)
            choisir(exercise.id)
            showCreateForm.value = false

            return
        }

        // Autre chose qu'un 2xx laissait la modale ouverte sans un mot, et
        // l'utilisateur tapait encore. Les erreurs de validation remontent.
        if (response.status === 422) {
            const { errors = {} } = await response.json()

            Object.entries(errors).forEach(([field, messages]) => {
                createExerciseForm.setError(field, [].concat(messages)[0])
            })
        } else {
            createExerciseForm.setError('name', 'La création a échoué. Réessaie dans un instant.')
        }

        triggerHaptic('error')
    } catch (e) {
        console.error(e)
        createExerciseForm.setError('name', 'Connexion impossible. Vérifie ta connexion réseau.')
        triggerHaptic('error')
    } finally {
        createExerciseForm.processing = false
    }
}

defineExpose({ searchQuery, showCreateForm, createExerciseForm, quickCreate, createAndAddExercise, fermer })
</script>

<template>
    <Modal :show="show" @close="fermer" max-width="lg" aria-labelledby="add-exercise-title">
        <div class="p-6">
            <h2 id="add-exercise-title" class="font-display text-text-main mb-6 text-2xl font-black uppercase italic">
                Ajouter un exercice
            </h2>
            <div v-if="!showCreateForm">
                <div class="pb-4">
                    <GlassInput
                        id="search-workout-exercise"
                        v-model="searchQuery"
                        type="search"
                        size="lg"
                        label="Rechercher un exercice"
                        hide-label
                        placeholder="Rechercher..."
                    />
                </div>
                <div class="max-h-[60vh] space-y-3 overflow-y-auto">
                    <button
                        v-if="filteredExercises.length === 0 && searchQuery"
                        type="button"
                        @click="quickCreate"
                        dusk="quick-create-exercise"
                        class="border-border hover:border-accent-state focus-visible:ring-accent-state flex w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed p-8 text-center transition-all focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span class="text-text-muted mb-2 block text-sm italic"
                            >Aucun résultat pour "{{ searchQuery }}"</span
                        >
                        <span class="text-accent-state-deep font-bold tracking-wider uppercase"
                            >Créer "{{ searchQuery }}"</span
                        >
                    </button>

                    <!-- Un bouton, pas un div : ajouter un exercice est l'action première de
                         cet écran, et elle était hors de portée du clavier. Des spans plutôt
                         que h4/p : du contenu de flux est invalide dans un bouton. -->
                    <button
                        v-for="exercise in filteredExercises"
                        :key="exercise.id"
                        type="button"
                        @click="choisir(exercise.id)"
                        :dusk="`select-exercise-${exercise.id}`"
                        class="glass-panel-light hover:border-accent-primary/50 focus-visible:ring-accent-primary block w-full cursor-pointer rounded-2xl p-4 text-left transition-all focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span class="text-text-main block font-bold">{{ exercise.name }}</span>
                        <span class="text-text-muted block text-xs uppercase">{{ exercise.category }}</span>
                    </button>
                </div>
            </div>

            <div v-else class="space-y-6">
                <div class="flex items-center gap-4">
                    <GlassIconButton icon="arrow_back" label="Retour" @click="showCreateForm = false" />
                    <h3 class="font-display text-text-main text-xl font-black uppercase italic">Nouvel Exercice</h3>
                </div>

                <form @submit.prevent="createAndAddExercise" class="space-y-4">
                    <GlassInput
                        v-model="createExerciseForm.name"
                        label="Nom"
                        dusk="new-exercise-name"
                        :error="createExerciseForm.errors.name"
                        required
                    />

                    <div class="grid grid-cols-2 gap-4">
                        <GlassSelect
                            v-model="createExerciseForm.type"
                            label="Type"
                            :options="EXERCISE_TYPES"
                            dusk="new-exercise-type"
                        />
                        <GlassSelect
                            v-model="createExerciseForm.category"
                            label="Catégorie"
                            :options="EXERCISE_CATEGORIES.map((c) => ({ value: c, label: c }))"
                            empty-label="— Aucune —"
                            dusk="new-exercise-category"
                        />
                    </div>

                    <GlassButton
                        type="submit"
                        variant="primary"
                        class="w-full"
                        :loading="createExerciseForm.processing"
                        dusk="submit-new-exercise"
                    >
                        Créer et Ajouter
                    </GlassButton>
                </form>
            </div>
        </div>
    </Modal>
</template>
