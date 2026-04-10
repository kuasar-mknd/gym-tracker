<script setup>
/**
 * ExerciseCard Component
 *
 * This component displays an individual exercise item. It supports two modes:
 * - View Mode: Displays the exercise name, icon, and category with swipeable row actions for mobile.
 * - Edit Mode: Provides an inline form to update the exercise's name, type, and category.
 *
 * It relies on parent state to determine if it is currently being edited.
 */

import { router } from '@inertiajs/vue3'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    /** The exercise object to display. */
    exercise: { type: Object, required: true },
    /** Determines if the component should display the inline edit form. */
    isEditing: { type: Boolean, required: true },
    /** The form object used for updating the exercise (usually an Inertia form). */
    editForm: { type: Object, required: true },
    /** The category name of the exercise to determine visual styles (like border colors). */
    category: { type: String, required: true },
    /** Array of available exercise types (e.g., strength, cardio) for the edit select dropdown. */
    types: { type: Array, required: true },
    /** Array of available exercise categories for the edit select dropdown. */
    categories: { type: Array, required: true },
    /** Mapping of categories to their specific border color Tailwind classes. */
    categoryBorderColors: { type: Object, required: true },
    /** Mapping of exercise types to their corresponding Google Material Icons string. */
    typeIcons: { type: Object, required: true },
    /** Function to format or translate the exercise type label for display. */
    typeLabel: { type: Function, required: true },
})

const emit = defineEmits([
    /** Fired when the user clicks the edit button or swipes to edit. Passes the exercise object. */
    'start-edit',
    /** Fired when the user cancels the inline editing form. */
    'cancel-edit',
    /** Fired when the edit form is submitted to save changes. Passes the exercise object. */
    'update',
    /** Fired when the user clicks the delete button or swipes to delete. Passes the exercise ID. */
    'delete',
])
</script>

<template>
    <SwipeableRow :disabled="isEditing" :action-threshold="80" class="mb-3 block">
        <template #action-left>
            <button
                @click="emit('start-edit', exercise)"
                class="flex h-full w-full items-center justify-start bg-blue-500 pl-6 text-white"
                data-testid="edit-exercise-button-mobile"
            >
                <div class="flex flex-col items-center">
                    <span class="material-symbols-outlined text-2xl">edit</span>
                    <span class="text-[10px] font-bold tracking-wider uppercase">Modifier</span>
                </div>
            </button>
        </template>

        <template #action-right>
            <button
                @click="emit('delete', exercise.id)"
                class="flex h-full w-full items-center justify-end bg-red-500 pr-6 text-white"
                data-testid="delete-exercise-button-mobile"
            >
                <div class="flex flex-col items-center">
                    <span class="material-symbols-outlined text-2xl">delete</span>
                    <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                </div>
            </button>
        </template>

        <GlassCard
            padding="p-4"
            :dusk="`exercise-card-${exercise.id}`"
            :class="[
                'group relative overflow-hidden transition-all duration-300',
                'border-l-[6px]',
                categoryBorderColors[category] || 'border-l-slate-300',
            ]"
            data-testid="exercise-card"
        >
            <!-- View Mode -->
            <div
                v-if="!isEditing"
                class="flex cursor-pointer items-center justify-between"
                @click="router.visit(route('exercises.show', { exercise: exercise.id }))"
            >
                <div class="flex items-center gap-4">
                    <div
                        :class="[
                            'flex size-14 items-center justify-center rounded-2xl',
                            exercise.type === 'strength'
                                ? 'bg-electric-orange/10 text-electric-orange'
                                : exercise.type === 'cardio'
                                  ? 'bg-neon-green/30 text-text-main'
                                  : 'bg-cyan-pure/10 text-cyan-pure',
                        ]"
                    >
                        <span class="material-symbols-outlined text-3xl">
                            {{ typeIcons[exercise.type] || 'fitness_center' }}
                        </span>
                    </div>
                    <div>
                        <div class="font-display text-text-main text-lg leading-tight font-bold uppercase italic">
                            {{ exercise.name }}
                        </div>
                        <div class="text-text-muted mt-1 text-xs font-semibold tracking-wider uppercase">
                            {{ typeLabel(exercise.type) }}
                        </div>
                    </div>
                </div>
                <div
                    class="flex items-center gap-2 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100"
                >
                    <button
                        @click.stop="emit('start-edit', exercise)"
                        :dusk="`edit-exercise-btn-${exercise.id}`"
                        class="text-text-muted hover:bg-electric-orange/10 hover:text-electric-orange focus-visible:ring-electric-orange flex size-10 items-center justify-center rounded-xl transition-all focus-visible:ring-2 focus-visible:outline-none sm:hidden"
                        :aria-label="`Modifier ${exercise.name}`"
                    >
                        <span class="material-symbols-outlined text-sm opacity-50">edit</span>
                    </button>

                    <!-- Desktop Buttons -->
                    <button
                        @click.stop="emit('start-edit', exercise)"
                        :dusk="`edit-exercise-btn-desktop-${exercise.id}`"
                        class="text-text-muted hover:bg-electric-orange/10 hover:text-electric-orange focus-visible:ring-electric-orange hidden size-10 items-center justify-center rounded-xl transition-all focus-visible:ring-2 focus-visible:outline-none sm:flex"
                        data-testid="edit-exercise-button"
                        :aria-label="`Modifier ${exercise.name}`"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                    </button>
                    <button
                        @click.stop="emit('delete', exercise.id)"
                        :dusk="`delete-exercise-btn-${exercise.id}`"
                        class="text-text-muted focus-visible:ring-electric-orange hidden size-10 items-center justify-center rounded-xl transition-all hover:bg-red-50 hover:text-red-500 focus-visible:ring-2 focus-visible:outline-none sm:flex"
                        data-testid="delete-exercise-button"
                        :aria-label="`Supprimer ${exercise.name}`"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                    </button>
                </div>
            </div>

            <!-- Edit Mode -->
            <form v-else @submit.prevent="emit('update', exercise)" class="space-y-4">
                <GlassInput
                    v-model="editForm.name"
                    dusk="edit-exercise-name"
                    placeholder="Nom de l'exercice"
                    :error="editForm.errors.name"
                />
                <div class="grid grid-cols-2 gap-3">
                    <select v-model="editForm.type" dusk="edit-exercise-type" class="glass-input text-sm">
                        <option v-for="t in types" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                    <select v-model="editForm.category" dusk="edit-exercise-category" class="glass-input text-sm">
                        <option value="">— Aucune —</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">
                            {{ cat }}
                        </option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <GlassButton
                        type="submit"
                        variant="primary"
                        size="sm"
                        dusk="save-exercise-btn"
                        :loading="editForm.processing"
                        data-testid="save-exercise-button"
                    >
                        Sauvegarder
                    </GlassButton>
                    <GlassButton
                        type="button"
                        variant="ghost"
                        size="sm"
                        dusk="cancel-edit-btn"
                        @click="emit('cancel-edit')"
                    >
                        Annuler
                    </GlassButton>
                </div>
            </form>
        </GlassCard>
    </SwipeableRow>
</template>
