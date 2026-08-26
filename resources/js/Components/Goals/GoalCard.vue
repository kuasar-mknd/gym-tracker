<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { parseCalendarDate } from '@/Utils/date'

const props = defineProps({
    goal: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['delete'])

const progress = computed(() => props.goal.progress_pct || 0)
const isCompleted = computed(() => !!props.goal.completed_at)

const typeIcon = computed(() => {
    switch (props.goal.type) {
        case 'weight':
            return '🏋️‍♂️'
        case 'frequency':
            return '📅'
        case 'volume':
            return '📊'
        case 'measurement':
            return '📏'
        default:
            return '🎯'
    }
})

const typeLabel = computed(() => {
    switch (props.goal.type) {
        case 'weight':
            return 'Poids (Max)'
        case 'frequency':
            return 'Fréquence'
        case 'volume':
            return 'Volume'
        case 'measurement':
            return 'Mesure'
        default:
            return 'Objectif'
    }
})

const formattedDeadline = computed(() => parseCalendarDate(props.goal.deadline)?.toLocaleDateString() ?? '')

const statusColor = computed(() => {
    if (isCompleted.value) return 'text-green-500'
    if (progress.value > 75) return 'text-blue-500'
    if (progress.value > 25) return 'text-accent-primary'
    return 'text-text-muted'
})
</script>

<template>
    <div
        v-press
        class="group border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-5 shadow-lg backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
    >
        <!-- Liquid Glow Background behind the card (subtle) -->
        <div
            class="bg-surface-card/5 absolute inset-0 z-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100"
        ></div>

        <!-- Completion Badge -->
        <div
            v-if="isCompleted"
            class="bg-accent-state text-text-on-accent absolute -top-2 -right-2 z-10 rotate-12 px-3 py-1.5 text-[10px] font-bold shadow-lg"
        >
            COMPLÉTÉ
        </div>

        <div class="relative z-10 mb-4 flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="border-surface-card/20 bg-surface-card/10 group-hover:bg-surface-card/20 flex h-12 w-12 items-center justify-center rounded-2xl border text-2xl shadow-sm backdrop-blur-md transition-transform duration-300 group-hover:scale-110"
                >
                    {{ typeIcon }}
                </div>
                <div>
                    <h4 class="font-display text-text-main line-clamp-1 text-lg font-black uppercase italic">
                        {{ goal.title }}
                    </h4>
                    <span class="text-text-muted text-[10px] font-bold tracking-wider uppercase">{{ typeLabel }}</span>
                </div>
            </div>

            <div class="text-right">
                <div class="font-display text-lg font-black italic drop-shadow-sm" :class="statusColor">
                    {{ Math.round(progress) }}%
                </div>
            </div>
        </div>

        <div class="relative z-10 space-y-4">
            <!-- Progress Bar Container -->
            <div class="space-y-1.5">
                <div class="text-text-muted flex justify-between text-[10px] font-bold tracking-widest uppercase">
                    <span>{{ goal.start_value }} {{ goal.unit }}</span>
                    <span>{{ goal.target_value }} {{ goal.unit }}</span>
                </div>
                <div
                    class="border-surface-card/20 bg-surface-card/10 h-2 w-full overflow-hidden rounded-full border shadow-inner backdrop-blur-md"
                    role="progressbar"
                    :aria-valuenow="progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="relative h-full transition-all duration-1000 ease-out"
                        :class="
                            isCompleted
                                ? 'bg-accent-state'
                                : 'from-electric-orange to-hot-pink shadow-glow-orange bg-linear-to-r'
                        "
                        :style="{ width: progress + '%' }"
                    >
                        <div class="absolute inset-0 animate-pulse bg-linear-to-r from-transparent to-white/30"></div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3">
                <div
                    class="border-surface-card/20 bg-surface-card/10 group-hover:bg-surface-card/20 rounded-2xl border p-3 shadow-sm backdrop-blur-md transition-colors"
                >
                    <p class="text-text-muted text-[10px] font-black tracking-widest uppercase">Actuel</p>
                    <p class="font-display text-text-main mt-0.5 text-lg font-black italic">
                        {{ goal.current_value }}
                        <span class="text-text-muted text-[10px] font-bold not-italic">{{ goal.unit }}</span>
                    </p>
                </div>
                <div
                    class="border-surface-card/20 bg-surface-card/10 group-hover:bg-surface-card/20 rounded-2xl border p-3 shadow-sm backdrop-blur-md transition-colors"
                >
                    <p class="text-text-muted text-[10px] font-black tracking-widest uppercase">Cible</p>
                    <p class="font-display text-text-main mt-0.5 text-lg font-black italic">
                        {{ goal.target_value }}
                        <span class="text-text-muted text-[10px] font-bold not-italic">{{ goal.unit }}</span>
                    </p>
                </div>
            </div>

            <div
                v-if="goal.deadline"
                class="text-text-muted flex items-center gap-1.5 pt-1 text-[10px] font-bold tracking-wider uppercase"
            >
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>
                <span>Échéance : {{ formattedDeadline }}</span>
            </div>

            <!--
              Both names carry the goal title. A screen reader listing the
              controls of a page holding six goals otherwise reads "Modifier,
              Supprimer" six times over with nothing to tell them apart.
            -->
            <div class="border-surface-card/10 flex items-center gap-2 border-t pt-3">
                <Link
                    :href="route('goals.edit', { goal: goal.id })"
                    :dusk="`edit-goal-${goal.id}`"
                    :aria-label="`Modifier l'objectif ${goal.title}`"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange min-h-touch border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border text-xs font-bold uppercase transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                    Modifier
                </Link>
                <button
                    type="button"
                    :dusk="`delete-goal-${goal.id}`"
                    :aria-label="`Supprimer l'objectif ${goal.title}`"
                    class="focus-visible:ring-electric-orange min-h-touch border-accent-danger/30 bg-accent-danger/10 text-accent-danger hover:bg-accent-danger/20 inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border text-xs font-bold uppercase transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="emit('delete', goal)"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                    Supprimer
                </button>
            </div>
        </div>
    </div>
</template>
