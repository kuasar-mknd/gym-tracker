<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

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
            <h3 class="text-text-main font-semibold">
                {{ editingJournal ? "Modifier l'entrée" : 'Nouvelle entrée' }}
            </h3>
            <button
                v-press
                @click="emit('close')"
                class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange rounded-md focus-visible:ring-2 focus-visible:outline-none"
                aria-label="Fermer le formulaire"
            >
                ✕
            </button>
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
                            'focus-visible:ring-electric-orange flex-1 rounded-2xl border border-white/20 p-2 text-center text-sm backdrop-blur-md transition-all duration-300 focus-visible:ring-2 focus-visible:outline-none',
                            form.mood_score === mood.value
                                ? 'bg-accent-primary border-transparent text-white shadow-lg'
                                : 'text-text-muted bg-white/10 hover:bg-white/20 hover:text-white',
                        ]"
                    >
                        <div class="text-xl" aria-hidden="true">{{ mood.label.split(' ')[0] }}</div>
                    </button>
                </div>
                <div v-if="form.errors.mood_score" class="mt-1 text-xs text-red-400">
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
                <div class="mb-1 flex items-center justify-between">
                    <label for="journal-content" class="text-text-muted block text-sm font-medium">Notes</label>
                    <span
                        id="journal-content-counter"
                        class="text-[10px] font-bold tracking-wider uppercase"
                        :class="form.content?.length > 1000 ? 'text-red-400' : 'text-text-muted/50'"
                    >
                        {{ form.content?.length || 0 }} / 1000
                    </span>
                </div>
                <textarea
                    id="journal-content"
                    v-model="form.content"
                    rows="4"
                    maxlength="1000"
                    aria-describedby="journal-content-counter"
                    class="text-text-main placeholder-text-muted/50 w-full rounded-2xl border border-white/20 bg-white/10 px-4 py-3 backdrop-blur-md transition-all duration-300 hover:border-white/30 hover:bg-white/15 focus:border-white/50 focus:bg-white/20 focus:shadow-[0_0_15px_rgba(255,255,255,0.1)] focus:ring-0 focus:outline-none"
                    placeholder="Comment s'est passée votre journée ? Entraînement, repas, sensations..."
                ></textarea>
                <div v-if="form.errors.content" class="mt-1 text-xs text-red-400">
                    {{ form.errors.content }}
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <GlassButton type="button" variant="secondary" @click="emit('close')"> Annuler </GlassButton>
                <GlassButton type="submit" variant="primary" :loading="form.processing"> Enregistrer </GlassButton>
            </div>
        </form>
    </GlassCard>
</template>
