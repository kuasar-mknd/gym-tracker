<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
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
    // Les plafonds que les requêtes d'un modèle valident (`WorkoutTemplate::bornes()`
    // et `Set::bornes()`) : absents, le formulaire n'en applique aucun.
    bornesDuModele: {
        type: Object,
        default: null,
    },
    bornesDUneSerie: {
        type: Object,
        default: null,
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

/**
 * Le formulaire n'offre pas d'ajouter ce que la requête refuserait : au-delà
 * de ces plafonds, l'enregistrement échouait en entier.
 */
const peutAjouterUnExercice = computed(
    () => !props.bornesDuModele || form.exercises.length < props.bornesDuModele.exercices,
)
const peutAjouterUneSerie = (exercise) =>
    !props.bornesDuModele || exercise.sets.length < props.bornesDuModele.seriesParExercice

/**
 * Les messages du serveur pour ces clefs, dans leur ordre.
 *
 * Les règles d'un modèle portent sur chaque exercice et chaque série
 * (`exercises.0.sets.1.reps`) : un refus que le formulaire n'affichait pas
 * laissait « Enregistrer » sans effet visible.
 *
 * @param {...string} cles
 * @returns {string[]}
 */
const erreursDe = (...cles) => cles.map((cle) => form.errors[cle]).filter(Boolean)

const aDesErreurs = computed(() => Object.keys(form.errors ?? {}).length > 0)

const addExercise = (exerciseId) => {
    const exercise = localExercises.value.find((e) => e.id === exerciseId)

    if (!exercise || !peutAjouterUnExercice.value) return

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
    if (!peutAjouterUneSerie(form.exercises[exerciseIndex])) return

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
                <h3 class="titre-carte mb-3">Exercices</h3>

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
                                <div v-for="(set, setIndex) in exercise.sets" :key="setIndex">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="text-text-muted bg-surface-sunken flex h-8 w-8 items-center justify-center rounded-lg text-xs font-bold"
                                        >
                                            {{ setIndex + 1 }}
                                        </div>
                                        <input
                                            v-model="set.reps"
                                            type="number"
                                            min="0"
                                            :max="bornesDUneSerie?.reps"
                                            :aria-invalid="
                                                Boolean(form.errors[`exercises.${exIndex}.sets.${setIndex}.reps`])
                                            "
                                            class="text-text-main placeholder:text-text-muted/40 border-border bg-surface-card/50 h-10 w-20 rounded-lg border text-center text-base"
                                            placeholder="réps"
                                        />
                                        <input
                                            v-model="set.weight"
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            :max="bornesDUneSerie?.weight"
                                            :aria-invalid="
                                                Boolean(form.errors[`exercises.${exIndex}.sets.${setIndex}.weight`])
                                            "
                                            class="text-text-main placeholder:text-text-muted/40 border-border bg-surface-card/50 h-10 w-20 rounded-lg border text-center text-base"
                                            placeholder="kg"
                                        />
                                        <button
                                            v-press="{ haptic: 'selection' }"
                                            @click="set.is_warmup = !set.is_warmup"
                                            type="button"
                                            class="focus-visible:ring-accent-primary text-2xs relative h-10 min-w-10 rounded-lg px-2 py-1 font-bold transition before:absolute before:-inset-0.5 before:content-[''] focus-visible:ring-2 focus-visible:outline-none"
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
                                    <p
                                        v-for="message in erreursDe(
                                            `exercises.${exIndex}.sets.${setIndex}.reps`,
                                            `exercises.${exIndex}.sets.${setIndex}.weight`,
                                            `exercises.${exIndex}.sets.${setIndex}.is_warmup`,
                                        )"
                                        :key="message"
                                        class="text-accent-danger-deep mt-1 text-sm font-medium"
                                        :dusk="`template-set-error-${exIndex}-${setIndex}`"
                                    >
                                        {{ message }}
                                    </p>
                                </div>
                                <p
                                    v-for="message in erreursDe(`exercises.${exIndex}.id`, `exercises.${exIndex}.sets`)"
                                    :key="message"
                                    class="text-accent-danger-deep text-sm font-medium"
                                    :dusk="`template-exercise-error-${exIndex}`"
                                >
                                    {{ message }}
                                </p>
                                <GlassButton
                                    v-press
                                    variant="primary"
                                    size="sm"
                                    icon="add"
                                    :dusk="`add-set-${exIndex}`"
                                    :disabled="!peutAjouterUneSerie(exercise)"
                                    @click="addSet(exIndex)"
                                >
                                    Ajouter une série
                                </GlassButton>
                                <p v-if="!peutAjouterUneSerie(exercise)" class="text-text-muted text-xs">
                                    {{ bornesDuModele.seriesParExercice }} séries au plus par exercice.
                                </p>
                            </div>
                        </GlassCard>
                    </div>

                    <p
                        v-for="message in erreursDe('exercises')"
                        :key="message"
                        class="text-accent-danger-deep text-sm font-medium"
                    >
                        {{ message }}
                    </p>

                    <GlassButton
                        @click="showAddExercise = true"
                        type="button"
                        variant="primary"
                        class="w-full"
                        dusk="open-add-exercise"
                        :disabled="!peutAjouterUnExercice"
                    >
                        + Ajouter un exercice
                    </GlassButton>
                    <p v-if="!peutAjouterUnExercice" class="text-text-muted text-xs">
                        {{ bornesDuModele.exercices }} exercices au plus par modèle.
                    </p>
                </div>
            </div>

            <div class="stagger-4 animate-slide-up pt-6">
                <p
                    v-if="aDesErreurs"
                    role="alert"
                    class="text-accent-danger-deep mb-3 text-sm font-medium"
                    dusk="template-form-errors"
                >
                    Le modèle n’a pas été enregistré : corrige les champs signalés.
                </p>
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
