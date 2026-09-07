<!--
  @component JournalForm
  @description A form component for creating and editing daily journal entries, tracking metrics like mood, sleep, stress, energy, and nutrition.

  @prop {Object} form - Required. The Inertia form object containing the journal data and validation errors.
  @prop {Array} moods - Required. An array of mood options to display as selectable buttons.
  @prop {Object|null} editingJournal - Optional. The existing journal entry being edited, or null if creating a new entry.

  @emits close - Emitted when the user clicks the close or cancel buttons.
  @emits submit - Emitted when the user submits the form.
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
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

defineProps({
    form: { type: Object, required: true },
    moods: { type: Array, required: true },
    editingJournal: { type: Object, default: null },
})

const emit = defineEmits(['close', 'submit'])
</script>

<template>
    <GlassCard class="animate-slide-up">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="titre-carte">
                {{ editingJournal ? "Modifier l'entrée" : 'Nouvelle entrée' }}
            </h3>
            <GlassIconButton v-press icon="close" label="Fermer le formulaire" @click="emit('close')" />
        </div>

        <form @submit.prevent="emit('submit')" class="space-y-4">
            <GlassInput v-model="form.date" type="date" label="Date" :error="form.errors.date" required />

            <div>
                <label id="mood-label" class="text-text-muted mb-1 block text-sm font-medium">Humeur</label>
                <div class="flex gap-2" role="radiogroup" aria-labelledby="mood-label">
                    <button
                        v-for="mood in moods"
                        :key="mood.value"
                        v-press="{ haptic: 'selection' }"
                        type="button"
                        @click="form.mood_score = mood.value"
                        role="radio"
                        :aria-checked="form.mood_score === mood.value"
                        :aria-label="mood.label"
                        :title="mood.label"
                        :class="[
                            'focus-visible:ring-accent-primary border-surface-card/20 flex-1 rounded-2xl border p-2 text-center text-sm backdrop-blur-md transition duration-300 focus-visible:ring-2 focus-visible:outline-none',
                            form.mood_score === mood.value
                                ? 'accent-fill border-transparent shadow-lg'
                                : 'text-text-muted bg-surface-card/10 hover:bg-surface-card/20',
                        ]"
                    >
                        <div class="text-xl" aria-hidden="true">{{ mood.label.split(' ')[0] }}</div>
                    </button>
                </div>
                <div v-if="form.errors.mood_score" class="text-accent-danger-deep mt-1 text-xs">
                    {{ form.errors.mood_score }}
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <GlassInput
                    v-model="form.sleep_quality"
                    type="number"
                    min="1"
                    max="5"
                    label="Sommeil (1-5)"
                    placeholder="Qualité"
                    :error="form.errors.sleep_quality"
                />
                <GlassInput
                    v-model="form.stress_level"
                    type="number"
                    min="1"
                    max="10"
                    label="Stress (1-10)"
                    placeholder="Niveau"
                    :error="form.errors.stress_level"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <GlassInput
                    v-model="form.energy_level"
                    type="number"
                    min="1"
                    max="10"
                    label="Énergie (1-10)"
                    placeholder="Niveau"
                    :error="form.errors.energy_level"
                />
                <GlassInput
                    v-model="form.motivation_level"
                    type="number"
                    min="1"
                    max="10"
                    label="Motivation (1-10)"
                    placeholder="Niveau"
                    :error="form.errors.motivation_level"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <GlassInput
                    v-model="form.nutrition_score"
                    type="number"
                    min="1"
                    max="5"
                    label="Diète (1-5)"
                    placeholder="Qualité"
                    :error="form.errors.nutrition_score"
                />
                <GlassInput
                    v-model="form.training_intensity"
                    type="number"
                    min="1"
                    max="10"
                    label="Intensité (1-10)"
                    placeholder="Effort"
                    :error="form.errors.training_intensity"
                />
            </div>

            <div>
                <GlassTextarea
                    v-model="form.content"
                    label="Notes"
                    :rows="4"
                    :maxlength="1000"
                    placeholder="Comment s'est passée votre journée ? Entraînement, repas, sensations..."
                    :error="form.errors.content"
                />
                <div v-if="form.errors.content" class="text-accent-danger-deep mt-1 text-xs">
                    {{ form.errors.content }}
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <GlassButton type="button" variant="secondary" block @click="emit('close')"> Annuler </GlassButton>
                <GlassButton type="submit" variant="primary" block :loading="form.processing">
                    Enregistrer
                </GlassButton>
            </div>
        </form>
    </GlassCard>
</template>
