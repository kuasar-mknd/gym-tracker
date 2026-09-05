<script setup>
/**
 * Une serie de la seance : sa coche, son numero (poignee du clavier), ses
 * mesures selon le type d'exercice, et son retrait. Les ecritures restent a
 * la page ; la rangee dit ce que l'utilisateur a fait.
 */
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import DurationWheel from '@/Components/Workout/DurationWheel.vue'

defineProps({
    set: { type: Object, required: true },
    index: { type: Number, required: true },
    lineIndex: { type: Number, required: true },
    line: { type: Object, required: true },
    isFinished: { type: Boolean, default: false },
    unsynced: { type: Boolean, default: false },
    reordonnable: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
})

defineEmits(['toggle', 'remove', 'saisie-en-cours', 'saisie-terminee', 'update', 'deplacer', 'pointerdown'])
</script>

<template>
    <SwipeableRow :disabled="disabled">
        <!-- The row was swipeable with no action behind it: dragging
         it snapped it open onto an empty background and left it
         there. Same delete the row's own button calls, reached
         the way Workouts/Index and ExerciseCard already do it. -->
        <template #action-right>
            <button
                type="button"
                @click="$emit('remove')"
                :dusk="`swipe-remove-set-${lineIndex}-${index}`"
                :aria-label="`Supprimer la série ${index + 1}`"
                class="bg-accent-danger text-text-on-accent flex h-full w-full items-center justify-center"
            >
                <span class="flex flex-col items-center" aria-hidden="true">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">delete</span>
                    <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                </span>
            </button>
        </template>

        <!--
          La rangee ENTIERE est la poignee. Une poignee
          dediee a ete essayee deux fois : l'icone prenait
          une place qu'on n'a pas, et le numero seul se
          ratait une fois sur deux. Les zones cliquables
          s'en retirent une a une, ci-dessous.
        -->
        <div
            :data-poignee-serie="reordonnable ? '' : undefined"
            @pointerdown="$emit('pointerdown', $event)"
            class="border-surface-card bg-surface-card/80 carte-portable flex items-center gap-2 rounded-2xl border p-3 shadow-sm"
            :class="{ 'opacity-50': set.is_completed }"
        >
            <button
                v-press
                @click="$emit('toggle')"
                :disabled="isFinished"
                :dusk="`complete-set-${lineIndex}-${index}`"
                class="group relative flex size-11 shrink-0 items-center justify-center rounded-xl border-2 transition-all"
                :class="set.is_completed ? 'bg-accent-state text-text-main' : 'bg-surface-sunken text-text-muted'"
                :aria-label="set.is_completed ? 'Annuler la série' : 'Valider la série'"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <!-- PR Trophy Badge -->
                <div
                    v-if="set.personal_record"
                    class="bg-accent-warning text-text-on-accent absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full shadow-sm"
                    :dusk="`pr-trophy-${lineIndex}-${index}`"
                >
                    <span class="material-symbols-outlined text-[12px] font-bold" aria-hidden="true">stars</span>
                </div>
            </button>
            <!-- Le numero porte le deplacement au CLAVIER. Le
                 doigt, lui, saisit la rangee entiere. -->
            <component
                :is="reordonnable ? 'button' : 'div'"
                :type="reordonnable ? 'button' : undefined"
                :data-poignee-clavier="reordonnable ? '' : undefined"
                :dusk="`reorder-set-${lineIndex}-${index}`"
                :aria-label="reordonnable ? `Déplacer la série ${index + 1}` : undefined"
                class="text-text-muted bg-surface-sunken focus-visible:ring-accent-primary relative flex h-11 w-6 shrink-0 items-center justify-center rounded-lg text-sm font-black select-none focus-visible:ring-2 focus-visible:outline-none"
                @keydown.up.prevent="$emit('deplacer', index - 1)"
                @keydown.down.prevent="$emit('deplacer', index + 1)"
            >
                {{ index + 1 }}

                <!-- The value on screen is not the value in the
                 database. Said out loud rather than left to a
                 colour, and not with a title attribute, which
                 a touch device never shows. -->
                <span
                    v-if="unsynced"
                    class="bg-accent-warning text-text-on-accent absolute -top-1 -right-1 flex size-3.5 items-center justify-center rounded-full"
                    :dusk="`set-unsynced-${lineIndex}-${index}`"
                    role="img"
                    :aria-label="`Série ${index + 1} non enregistrée`"
                >
                    <span class="material-symbols-outlined text-[10px]" aria-hidden="true">cloud_off</span>
                </span>
            </component>

            <template v-if="line.exercise.type === 'strength'">
                <input
                    type="number"
                    inputmode="decimal"
                    :value="set.weight"
                    @focus="$event.target.select()"
                    @input="(e) => $emit('saisie-en-cours', 'weight', e.target.value)"
                    @change="(e) => $emit('saisie-terminee', 'weight', e.target.value)"
                    :disabled="isFinished"
                    :dusk="`weight-input-${lineIndex}-${index}`"
                    :aria-label="`Poids en kg, série ${index + 1}, ${line.exercise.name}`"
                    class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                />
                <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true">kg</span>
                <input
                    type="number"
                    inputmode="numeric"
                    :value="set.reps"
                    @focus="$event.target.select()"
                    @input="(e) => $emit('saisie-en-cours', 'reps', e.target.value)"
                    @change="(e) => $emit('saisie-terminee', 'reps', e.target.value)"
                    :disabled="isFinished"
                    :dusk="`reps-input-${lineIndex}-${index}`"
                    :aria-label="`Répétitions, série ${index + 1}, ${line.exercise.name}`"
                    class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                />
                <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true">réps</span>
            </template>

            <template v-else-if="line.exercise.type === 'cardio'">
                <input
                    type="number"
                    step="0.1"
                    inputmode="decimal"
                    :value="set.distance_km"
                    @focus="$event.target.select()"
                    @input="(e) => $emit('saisie-en-cours', 'distance_km', e.target.value)"
                    @change="(e) => $emit('saisie-terminee', 'distance_km', e.target.value)"
                    :disabled="isFinished"
                    :dusk="`distance-input-${lineIndex}-${index}`"
                    :aria-label="`Distance en km, série ${index + 1}, ${line.exercise.name}`"
                    class="text-text-main border-border h-11 w-full min-w-0 flex-1 rounded-xl border-2 text-center font-bold"
                />
                <span class="text-text-muted shrink-0 text-xs font-bold" aria-hidden="true">km</span>
                <DurationWheel
                    :model-value="set.duration_seconds"
                    @update:model-value="(seconds) => $emit('update', 'duration_seconds', seconds)"
                    :disabled="isFinished"
                    :fill="false"
                    :dusk="`duration-input-${lineIndex}-${index}`"
                    :label="`Durée, série ${index + 1}, ${line.exercise.name}`"
                />
            </template>

            <template v-else-if="line.exercise.type === 'timed'">
                <DurationWheel
                    :model-value="set.duration_seconds"
                    @update:model-value="(seconds) => $emit('update', 'duration_seconds', seconds)"
                    :disabled="isFinished"
                    :dusk="`duration-input-${lineIndex}-${index}`"
                    :label="`Durée, série ${index + 1}, ${line.exercise.name}`"
                />
            </template>

            <button
                v-if="!isFinished"
                v-press="{ haptic: 'warning' }"
                @click="$emit('remove')"
                :dusk="`remove-set-${lineIndex}-${index}`"
                :class="[
                    'hover:text-accent-danger-deep text-text-muted relative ml-auto',
                    'before:absolute before:-inset-2.5 before:content-[\'\']',
                    // Redundant on a phone, where the row swipes.
                    // Kept from sm up, where there is no swipe at
                    // all: SwipeableRow listens for touch events
                    // only, so a mouse has no other way to delete.
                    'hidden sm:block',
                ]"
                aria-label="Supprimer la série"
            >
                <span class="material-symbols-outlined" aria-hidden="true">delete</span>
            </button>
        </div>
    </SwipeableRow>
</template>
