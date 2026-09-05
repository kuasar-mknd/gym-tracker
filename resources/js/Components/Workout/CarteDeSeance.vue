<script setup>
/**
 * Une séance de l'historique : son titre, sa date, un aperçu de ses exercices,
 * et le glissement qui propose de la supprimer. La décision reste à la page.
 */
import { Link } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'

defineProps({
    seance: { type: Object, required: true },
})

defineEmits(['supprimer'])

const formatDate = (dateStr) =>
    new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    })
</script>

<template>
    <SwipeableRow class="mb-3 block" :action-threshold="80">
        <template #action-right>
            <button
                type="button"
                @click="$emit('supprimer', seance)"
                :dusk="`delete-workout-${seance.id}`"
                :aria-label="`Supprimer la séance ${seance.name || 'sans nom'}`"
                class="text-text-on-dark-accent flex h-full w-full items-center justify-center transition-all active:scale-95"
                style="
                    background: linear-gradient(
                        135deg,
                        var(--color-accent-danger) 0%,
                        var(--color-accent-danger-deep) 100%
                    );
                    box-shadow: inset 0 2px 4px rgb(from var(--color-surface-card) r g b / 0.2);
                "
            >
                <div class="flex flex-col items-center drop-shadow-md" aria-hidden="true">
                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">delete</span>
                    <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                </div>
            </button>
        </template>

        <Link :href="route('workouts.show', { workout: seance.id })" class="block">
            <GlassCard class="hover:bg-surface-glass-strong transition active:scale-[0.99]">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h4 class="text-text-main font-semibold">
                                {{ seance.name || 'Séance' }}
                            </h4>
                            <span class="glass-badge glass-badge-primary text-xs">
                                {{ seance.workout_lines.length }} exo
                            </span>
                        </div>
                        <div class="text-text-muted mt-1 text-sm">
                            {{ formatDate(seance.started_at) }}
                        </div>

                        <div v-if="seance.workout_lines.length > 0" class="mt-3 flex flex-wrap gap-2">
                            <span
                                v-for="line in seance.workout_lines.slice(0, 3)"
                                :key="line.id"
                                class="text-text-muted border-border bg-surface-card/50 rounded-lg border px-2 py-1 text-xs"
                            >
                                {{ line.exercise.name }}
                                <span class="text-text-muted/50">• {{ line.sets_count }} séries</span>
                            </span>
                            <span
                                v-if="seance.workout_lines.length > 3"
                                class="text-text-muted/50 border-border bg-surface-card/50 rounded-lg border px-2 py-1 text-xs"
                            >
                                +{{ seance.workout_lines.length - 3 }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-text-muted/30 shrink-0 text-xl" aria-hidden="true"
                            >chevron_right</span
                        >
                    </div>
                </div>
            </GlassCard>
        </Link>
    </SwipeableRow>
</template>
