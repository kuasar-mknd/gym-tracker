<script setup>
/* eslint-disable vue/no-mutating-props --
 * The parent owns the Inertia useForm object and hands it down so this component
 * can v-model straight into its fields. Writing to a prop object's properties is
 * legal in Vue — the prop binding itself is never reassigned — and it is the
 * pattern this codebase uses for every shared form. The rule cannot tell that
 * apart from writing through a data prop, which is a real hazard and stays
 * reported everywhere else.
 */
/**
 * ExerciseCard Component
 *
 * This component displays an individual exercise item. It supports two modes:
 * - View Mode: Displays the exercise name, icon, and category with swipeable row actions for mobile.
 * - Edit Mode: Provides an inline form to update the exercise's name, type, and category.
 *
 * It relies on parent state to determine if it is currently being edited.
 */

import { Link } from '@inertiajs/vue3'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
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
                class="info-fill flex h-full w-full items-center justify-center"
                data-testid="edit-exercise-button-mobile"
            >
                <div class="flex flex-col items-center">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">edit</span>
                    <span class="text-[10px] font-bold tracking-wider uppercase">Modifier</span>
                </div>
            </button>
        </template>

        <template #action-right>
            <button
                @click="emit('delete', exercise.id)"
                class="danger-fill flex h-full w-full items-center justify-center"
                data-testid="delete-exercise-button-mobile"
            >
                <div class="flex flex-col items-center">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">delete</span>
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
                categoryBorderColors[category] || 'border-l-border-strong',
            ]"
            data-testid="exercise-card"
        >
            <!-- View Mode -->
            <div v-if="!isEditing" class="flex items-center justify-between">
                <!-- A Link, not a div @click: this is the only way into an exercise
                     detail page, and a div is neither focusable nor operable by
                     Enter. The action buttons stay OUTSIDE the anchor — nesting
                     buttons inside an <a> is invalid and hijacks the navigation. -->
                <Link
                    :href="route('exercises.show', { exercise: exercise.id })"
                    :dusk="`open-exercise-${exercise.id}`"
                    class="focus-visible:ring-accent-primary flex min-w-0 flex-1 items-center gap-4 rounded-2xl focus-visible:ring-2 focus-visible:outline-none"
                >
                    <div
                        :class="[
                            'flex size-14 items-center justify-center rounded-2xl',
                            exercise.type === 'strength'
                                ? 'bg-accent-primary/10 text-accent-primary-deep'
                                : exercise.type === 'cardio'
                                  ? 'bg-accent-state/30 text-text-main'
                                  : 'bg-accent-info/10 text-accent-info-deep',
                        ]"
                    >
                        <span class="material-symbols-outlined text-3xl" aria-hidden="true">
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
                </Link>
                <div
                    class="flex shrink-0 items-center gap-2 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"
                >
                    <GlassIconButton
                        icon="edit"
                        :label="`Modifier ${exercise.name}`"
                        ton="accent"
                        class="sm:hidden"
                        :dusk="`edit-exercise-btn-${exercise.id}`"
                        @click.stop="emit('start-edit', exercise)"
                    />

                    <!-- Desktop Buttons -->
                    <GlassIconButton
                        icon="edit"
                        :label="`Modifier ${exercise.name}`"
                        ton="accent"
                        class="hidden sm:inline-flex"
                        :dusk="`edit-exercise-btn-desktop-${exercise.id}`"
                        data-testid="edit-exercise-button"
                        @click.stop="emit('start-edit', exercise)"
                    />
                    <GlassIconButton
                        icon="delete"
                        :label="`Supprimer ${exercise.name}`"
                        ton="danger"
                        class="hidden sm:inline-flex"
                        :dusk="`delete-exercise-btn-${exercise.id}`"
                        data-testid="delete-exercise-button"
                        @click.stop="emit('delete', exercise.id)"
                    />
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
                    <GlassSelect
                        v-model="editForm.type"
                        dusk="edit-exercise-type"
                        :options="types"
                        size="sm"
                        label="Type d'exercice"
                        hide-label
                    />
                    <GlassSelect
                        v-model="editForm.category"
                        dusk="edit-exercise-category"
                        :options="categories.map((c) => ({ value: c, label: c }))"
                        empty-label="— Aucune —"
                        size="sm"
                        label="Catégorie"
                        hide-label
                        placeholder=""
                    />
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
                        Enregistrer
                    </GlassButton>
                    <GlassButton
                        type="button"
                        variant="secondary"
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
