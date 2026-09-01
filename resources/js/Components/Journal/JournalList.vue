<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { parseCalendarDate } from '@/Utils/date'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

defineProps({
    journalsByMonth: { type: Object, required: true },
    moods: { type: Array, required: true },
})

const emit = defineEmits(['edit', 'delete'])
</script>

<template>
    <div class="space-y-8">
        <div v-for="(group, month) in journalsByMonth" :key="month">
            <h3
                class="text-text-main border-surface-card/20 bg-surface-card/10 sticky top-0 z-10 mb-4 rounded-2xl border p-2 text-lg font-medium capitalize shadow-lg backdrop-blur-md"
            >
                {{ month }}
            </h3>
            <div class="space-y-4">
                <GlassCard
                    v-for="journal in group"
                    :key="journal.id"
                    class="group border-surface-card/20 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-[0.98]"
                    padding="p-0"
                >
                    <div class="flex flex-col sm:flex-row">
                        <!-- Date Column -->
                        <div
                            class="bg-surface-card/5 sm:border-surface-card/10 flex w-full shrink-0 flex-row items-center justify-between p-4 sm:w-24 sm:flex-col sm:justify-center sm:border-r"
                        >
                            <div class="text-center">
                                <div class="text-text-muted text-xs uppercase">
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

                            <!-- Mobile Mood Display -->
                            <div v-if="journal.mood_score" class="text-2xl sm:hidden">
                                {{ moods.find((m) => m.value === journal.mood_score)?.label.split(' ')[0] }}
                            </div>
                        </div>

                        <!-- Content Column -->
                        <div class="flex-1 p-4">
                            <div class="mb-2 flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div v-if="journal.mood_score" class="hidden text-2xl sm:block" title="Humeur">
                                        {{ moods.find((m) => m.value === journal.mood_score)?.label.split(' ')[0] }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-if="journal.sleep_quality"
                                            class="bg-accent-tertiary/10 text-accent-tertiary-deep ring-accent-tertiary/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            💤 {{ journal.sleep_quality }}/5
                                        </span>
                                        <span
                                            v-if="journal.stress_level"
                                            class="bg-accent-primary/10 text-accent-primary-deep ring-accent-primary/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            ⚡ Stress: {{ journal.stress_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.energy_level"
                                            class="bg-accent-warning/10 text-accent-warning-deep ring-accent-warning/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            🔋 Énergie: {{ journal.energy_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.motivation_level"
                                            class="bg-accent-secondary/10 text-accent-secondary-deep ring-accent-secondary/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            🔥 Motivation: {{ journal.motivation_level }}/10
                                        </span>
                                        <span
                                            v-if="journal.nutrition_score"
                                            class="bg-accent-state/10 text-accent-state-deep ring-accent-state/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            🥗 Diète: {{ journal.nutrition_score }}/5
                                        </span>
                                        <span
                                            v-if="journal.training_intensity"
                                            class="bg-accent-danger/10 text-accent-danger-deep ring-accent-danger/30 inline-flex items-center rounded-md px-2 py-1 text-[10px] font-medium ring-1 ring-inset"
                                        >
                                            🏋️ Intensité: {{ journal.training_intensity }}/10
                                        </span>
                                    </div>
                                </div>

                                <div
                                    class="flex gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"
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
