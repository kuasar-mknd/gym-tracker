<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { parseCalendarDate } from '@/Utils/date'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

defineProps({
    journalsByMonth: { type: Object, required: true },
})

const emit = defineEmits(['edit', 'delete'])
</script>

<template>
    <div class="space-y-6">
        <div v-for="(group, month) in journalsByMonth" :key="month">
            <h3
                class="border-glass-border bg-surface-glass titre-plat top-sous-entete sticky z-10 mb-4 rounded-2xl border p-2 backdrop-blur-md"
                dusk="titre-du-mois"
            >
                {{ month }}
            </h3>
            <div class="space-y-4">
                <GlassCard
                    v-for="journal in group"
                    :key="journal.id"
                    class="group relative overflow-hidden active:scale-[0.98]"
                    padding="p-0"
                >
                    <div class="flex flex-col sm:flex-row">
                        <!-- Date Column -->
                        <div
                            class="bg-surface-card/5 sm:border-surface-card/10 flex w-full shrink-0 flex-row items-center justify-between p-4 sm:w-24 sm:flex-col sm:justify-center sm:border-r"
                        >
                            <div class="text-center">
                                <div class="text-text-muted text-xs">
                                    {{
                                        parseCalendarDate(journal.date)?.toLocaleDateString('fr-FR', {
                                            weekday: 'short',
                                        })
                                    }}
                                </div>
                                <div class="text-text-main text-2xl font-bold">
                                    {{ parseCalendarDate(journal.date)?.getDate() }}
                                </div>
                            </div>
                        </div>

                        <!-- Content Column -->
                        <div class="flex-1 p-4">
                            <div class="mb-2 flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-if="journal.mood_score"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Humeur {{ journal.mood_score }}/5
                                        </span>
                                        <span
                                            v-if="journal.sleep_quality"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Sommeil {{ journal.sleep_quality }}/5
                                        </span>
                                        <span
                                            v-if="journal.stress_level"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Stress {{ journal.stress_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.energy_level"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Énergie {{ journal.energy_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.motivation_level"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Motivation {{ journal.motivation_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.nutrition_score"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Diète {{ journal.nutrition_score }}/5
                                        </span>
                                        <span
                                            v-if="journal.training_intensity"
                                            class="bg-surface-glass-strong text-text-muted text-2xs inline-flex items-center rounded-md px-2 py-1 font-medium"
                                        >
                                            Intensité {{ journal.training_intensity }}/10
                                        </span>
                                    </div>
                                </div>

                                <div
                                    class="souris:opacity-0 souris:group-hover:opacity-100 souris:focus-within:opacity-100 flex gap-1 transition-opacity"
                                >
                                    <GlassIconButton
                                        v-press
                                        icon="edit"
                                        label="Modifier l'entrée"
                                        ton="accent"
                                        compact
                                        @click="emit('edit', journal)"
                                    />
                                    <GlassIconButton
                                        v-press
                                        icon="delete"
                                        label="Supprimer l'entrée"
                                        ton="danger"
                                        compact
                                        @click="emit('delete', journal.id)"
                                    />
                                </div>
                            </div>

                            <p class="text-text-main text-sm whitespace-pre-wrap">{{ journal.content }}</p>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>
    </div>
</template>
