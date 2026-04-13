<script setup>
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    show: { type: Boolean, required: true },
    form: { type: Object, required: true },
})

const emit = defineEmits(['close', 'submit'])
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="lg" aria-labelledby="workout-settings-title">
        <div class="p-6">
            <h2
                id="workout-settings-title"
                class="font-display text-text-main mb-6 text-2xl font-black uppercase italic"
            >
                Paramètres
            </h2>
            <form @submit.prevent="emit('submit')" class="space-y-5">
                <GlassInput v-model="form.name" label="Nom" dusk="workout-name-input" />
                <GlassInput v-model="form.started_at" type="datetime-local" label="Date" />

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <label for="workout-notes" class="font-display-label text-text-muted block text-sm">
                            Notes
                        </label>
                        <span
                            id="workout-notes-counter"
                            class="text-[10px] font-bold tracking-wider uppercase"
                            :class="form.notes?.length > 1000 ? 'text-red-400' : 'text-text-muted/50'"
                        >
                            {{ form.notes?.length || 0 }} / 1000
                        </span>
                    </div>
                    <textarea
                        id="workout-notes"
                        v-model="form.notes"
                        rows="4"
                        maxlength="1000"
                        aria-describedby="workout-notes-counter"
                        class="text-text-main placeholder:text-text-muted/50 w-full rounded-2xl border border-white/20 bg-white/10 px-4 py-3 backdrop-blur-md transition-all duration-300 hover:border-white/30 hover:bg-white/15 focus:border-white/50 focus:bg-white/20 focus:shadow-[0_0_15px_rgba(255,255,255,0.1)] focus:ring-0 focus:outline-none dark:border-slate-700 dark:bg-slate-800/80 dark:text-white dark:hover:border-slate-600 dark:focus:bg-slate-800"
                        placeholder="Notes sur la séance..."
                        dusk="workout-notes-input"
                    ></textarea>
                    <p v-if="form.errors.notes" class="mt-2 text-sm font-medium text-red-600">
                        {{ form.errors.notes }}
                    </p>
                </div>

                <GlassButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    class="w-full"
                    dusk="save-settings-button"
                >
                    Sauvegarder
                </GlassButton>
            </form>
        </div>
    </Modal>
</template>
