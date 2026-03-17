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
                class="text-text-muted hover:text-text-main"
                aria-label="Fermer le formulaire"
            >
                ✕
            </button>
        </div>

        <form @submit.prevent="emit('submit')" class="space-y-4">
            <GlassInput v-model="form.date" type="date" label="Date" :error="form.errors.date" required />

            <div>
                <label class="text-text-muted mb-1 block text-sm font-medium">Humeur</label>
                <div class="flex gap-2">
                    <button
                        v-for="mood in moods"
                        :key="mood.value"
                        v-press="{ haptic: 'selection' }"
                        type="button"
                        @click="form.mood_score = mood.value"
                        :aria-pressed="form.mood_score === mood.value"
                        :class="[
                            'flex-1 rounded-lg border border-slate-200 p-2 text-center text-sm transition',
                            form.mood_score === mood.value
                                ? 'bg-accent-primary border-transparent text-white'
                                : 'text-text-muted bg-white/50 hover:bg-slate-50',
                        ]"
                    >
                        <div class="text-xl">{{ mood.label.split(' ')[0] }}</div>
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
                <label class="text-text-muted mb-1 block text-sm font-medium">Notes</label>
                <textarea
                    v-model="form.content"
                    rows="4"
                    class="text-text-main placeholder-text-muted/30 focus:border-accent-primary focus:ring-accent-primary w-full rounded-xl border border-slate-200 bg-white/50 px-4 py-2 backdrop-blur-md focus:ring-1 focus:outline-none"
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
