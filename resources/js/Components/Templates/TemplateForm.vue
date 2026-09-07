<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'

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

const showAddExercise = ref(false)
const localExercises = ref([...(props.exercises || [])].filter((e) => e && e.id))

const addExercise = (exerciseId) => {
    const exercise = localExercises.value.find((e) => e.id === exerciseId)

    if (!exercise) return

    form.exercises.push({
        uid: nextUid++,
        id: exercise.id,
        name: exercise.name,
        sets: [{ reps: 10, weight: null, is_warmup: false }],
    })
    showAddExercise.value = false
}

/** Cree sur le champ : il rejoint la bibliotheque a sa place, et la modale le choisit. */
const ajouterALaBibliotheque = (exercise) => {
    localExercises.value.push(exercise)
    localExercises.value.sort((a, b) => a.name.localeCompare(b.name))
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
                        <GlassTextarea
                            v-model="form.description"
                            label="Description (optionnel)"
                            :rows="2"
                            :maxlength="1000"
                            placeholder="Détails de la séance..."
                            :error="form.errors.description"
                        />
                        <p v-if="form.errors.description" class="text-accent-danger-deep mt-2 text-sm font-medium">
                            {{ form.errors.description }}
                        </p>
                    </div>
                </div>
            </GlassCard>

            <div class="stagger-2 animate-slide-up">
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
                                        class="focus-visible:ring-accent-primary text-2xs h-10 rounded-lg px-2 py-1 font-bold transition focus-visible:ring-2 focus-visible:outline-none"
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
                                    class="text-accent-primary-deep focus-visible:ring-accent-primary rounded-lg text-xs transition hover:underline focus-visible:ring-2 focus-visible:outline-none"
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

            <div class="stagger-4 animate-slide-up pt-6">
                <GlassButton variant="primary" size="lg" class="w-full" :loading="form.processing" type="submit">
                    {{ template ? 'Mettre à jour le modèle' : 'Enregistrer le modèle' }}
                </GlassButton>
            </div>
        </form>

        <AjoutDExerciceModal
            :show="showAddExercise"
            :exercises="localExercises"
            @close="showAddExercise = false"
            @add="addExercise"
            @created="ajouterALaBibliotheque"
        />
    </div>
</template>
