<!--
  @component GoalForm
  @description The fields of a goal, shared by the create form on the index and
  the edit page. A second hand-written copy is how two screens that fill the
  same table drift apart — the create form used to be the only one, and it is
  the reason the edit page could not exist without duplicating it.

  @prop {Object} form - Required. The Inertia form object holding the goal data and its errors.
  @prop {Array} exercises - Required. Exercises selectable for a strength or volume goal.
  @prop {Array} measurementTypes - Required. Measurements selectable for a measurement goal.
  @prop {String} submitLabel - Text of the submit button.
  @prop {Boolean} autoTitle - Whether to keep the title in step with the selection. Off when editing, where a title already exists.

  @emits submit - The form was submitted.
  @emits cancel - The user backed out.
-->
<script setup>
/* eslint-disable vue/no-mutating-props --
 * The parent owns the Inertia useForm object and hands it down so this component
 * can v-model straight into its fields. Writing to a prop object's properties is
 * legal in Vue — the prop binding itself is never reassigned — and it is the
 * pattern this codebase uses for every shared form. The rule cannot tell that
 * apart from writing through a data prop, which is a real hazard and stays
 * reported everywhere else.
 */
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { ref, watch } from 'vue'

const props = defineProps({
    form: { type: Object, required: true },
    exercises: { type: Array, required: true },
    measurementTypes: { type: Array, required: true },
    submitLabel: { type: String, default: 'Enregistrer' },
    autoTitle: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const goalTypeOptions = [
    { value: 'weight', label: 'Force (Poids max)' },
    { value: 'frequency', label: 'Fréquence (Séances)' },
    { value: 'volume', label: 'Volume (Max par séance)' },
    { value: 'measurement', label: 'Mensuration' },
]

/**
 * Once the title has been typed in, the selection stops rewriting it. Without
 * this, choosing a different exercise silently threw away a title the user had
 * just written.
 */
const titleTouched = ref(false)

watch(
    // target_value belongs here as much as the other three: all four branches
    // below read it. Left out, the `|| '?'` fallback — meant for the moment the
    // type is chosen and the target is not yet typed — became permanent, and
    // the question mark was saved as the goal's title.
    () => [props.form.type, props.form.exercise_id, props.form.measurement_type, props.form.target_value],
    () => {
        if (!props.autoTitle || titleTouched.value) {
            return
        }

        if (props.form.type === 'weight' && props.form.exercise_id) {
            const exercise = props.exercises.find((candidate) => candidate.id == props.form.exercise_id)
            if (exercise) {
                props.form.title = `Soulever ${props.form.target_value || '?'} kg au ${exercise.name}`
            }
        } else if (props.form.type === 'measurement' && props.form.measurement_type) {
            const measurement = props.measurementTypes.find(
                (candidate) => candidate.value === props.form.measurement_type,
            )
            if (measurement) {
                const unit = props.form.measurement_type === 'body_fat' ? '%' : 'cm'
                props.form.title = `Atteindre ${props.form.target_value || '?'} ${unit} de ${measurement.label}`
            }
        } else if (props.form.type === 'frequency') {
            props.form.title = `Atteindre ${props.form.target_value || '?'} séances au total`
        }
    },
)
</script>

<template>
    <form class="space-y-6" @submit.prevent="emit('submit')">
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div class="space-y-4">
                <GlassSelect
                    v-model="form.type"
                    label="Type d'objectif"
                    :options="goalTypeOptions"
                    :error="form.errors.type"
                />

                <GlassSelect
                    v-if="form.type === 'weight' || form.type === 'volume'"
                    v-model="form.exercise_id"
                    label="Exercice"
                    :options="exercises.map((exercise) => ({ value: exercise.id, label: exercise.name }))"
                    placeholder="Sélectionner un exercice"
                    :error="form.errors.exercise_id"
                />

                <GlassSelect
                    v-if="form.type === 'measurement'"
                    v-model="form.measurement_type"
                    label="Mensuration"
                    :options="measurementTypes"
                    placeholder="Sélectionner une mesure"
                    :error="form.errors.measurement_type"
                />

                <!--
                  The card has always rendered an "Échéance" line when a goal
                  carries a deadline, and the server has always validated the
                  field — but no form ever offered it, so the value could not
                  be set and that line was unreachable.
                -->
                <GlassInput
                    v-model="form.deadline"
                    label="Échéance (Optionnel)"
                    type="date"
                    :error="form.errors.deadline"
                />
            </div>

            <div class="space-y-4">
                <GlassInput
                    v-model="form.target_value"
                    label="Valeur Cible"
                    type="number"
                    step="0.1"
                    required
                    :error="form.errors.target_value"
                />
                <GlassInput
                    v-model="form.start_value"
                    label="Valeur de Départ (Optionnel)"
                    type="number"
                    step="0.1"
                    placeholder="0"
                    :error="form.errors.start_value"
                />
                <GlassInput
                    v-model="form.title"
                    label="Titre du défi"
                    placeholder="Ex: Bench Press 100kg"
                    required
                    :error="form.errors.title"
                    @input="titleTouched = true"
                />
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-white/5 pt-4">
            <GlassButton type="button" variant="secondary" @click="emit('cancel')">Annuler</GlassButton>
            <GlassButton type="submit" :loading="form.processing" dusk="goal-submit">{{ submitLabel }}</GlassButton>
        </div>
    </form>
</template>
