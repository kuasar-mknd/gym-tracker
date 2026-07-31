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
    if (isCompleted.value) return 'text-green-500 dark:text-green-400'
    if (progress.value > 75) return 'text-blue-500 dark:text-blue-400'
    if (progress.value > 25) return 'text-orange-500 dark:text-orange-400'
    return 'text-gray-500 dark:text-white/60'
})
</script>

<template>
    <div
        v-press
        class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-5 shadow-lg backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl dark:bg-black/40"
    >
        <!-- Liquid Glow Background behind the card (subtle) -->
        <div
            class="absolute inset-0 z-0 bg-white/5 opacity-0 transition-opacity duration-300 group-hover:opacity-100"
        ></div>

        <!-- Completion Badge -->
        <div
            v-if="isCompleted"
            class="absolute -top-2 -right-2 z-10 rotate-12 bg-green-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-lg"
        >
            COMPLÉTÉ
        </div>

        <div class="relative z-10 mb-4 flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-white/20 bg-white/10 text-2xl shadow-sm backdrop-blur-md transition-transform duration-300 group-hover:scale-110 group-hover:bg-white/20"
                >
                    {{ typeIcon }}
                </div>
                <div>
                    <h4
                        class="font-display text-text-main line-clamp-1 text-lg font-black uppercase italic dark:text-white"
                    >
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
                <div
                    class="flex justify-between text-[10px] font-bold tracking-widest text-slate-500 uppercase dark:text-white/60"
                >
                    <span>{{ goal.start_value }} {{ goal.unit }}</span>
                    <span>{{ goal.target_value }} {{ goal.unit }}</span>
                </div>
                <div
                    class="h-2 w-full overflow-hidden rounded-full border border-white/20 bg-white/10 shadow-inner backdrop-blur-md"
                    role="progressbar"
                    :aria-valuenow="progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="relative h-full transition-all duration-1000 ease-out"
                        :class="
                            isCompleted
                                ? 'shadow-glow-green bg-green-500'
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
                    class="rounded-2xl border border-white/20 bg-white/10 p-3 shadow-sm backdrop-blur-md transition-colors group-hover:bg-white/20"
                >
                    <p class="text-[10px] font-black tracking-widest text-slate-500 uppercase dark:text-white/60">
                        Actuel
                    </p>
                    <p class="font-display mt-0.5 text-lg font-black text-slate-900 italic dark:text-white">
                        {{ goal.current_value }}
                        <span class="text-[10px] font-bold text-slate-500 not-italic dark:text-white/60">{{
                            goal.unit
                        }}</span>
                    </p>
                </div>
                <div
                    class="rounded-2xl border border-white/20 bg-white/10 p-3 shadow-sm backdrop-blur-md transition-colors group-hover:bg-white/20"
                >
                    <p class="text-[10px] font-black tracking-widest text-slate-500 uppercase dark:text-white/60">
                        Cible
                    </p>
                    <p class="font-display mt-0.5 text-lg font-black text-slate-900 italic dark:text-white">
                        {{ goal.target_value }}
                        <span class="text-[10px] font-bold text-slate-500 not-italic dark:text-white/60">{{
                            goal.unit
                        }}</span>
                    </p>
                </div>
            </div>

            <div
                v-if="goal.deadline"
                class="flex items-center gap-1.5 pt-1 text-[10px] font-bold tracking-wider text-slate-500 uppercase dark:text-white/60"
            >
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">schedule</span>
                <span>Échéance : {{ formattedDeadline }}</span>
            </div>

            <!--
              Both names carry the goal title. A screen reader listing the
              controls of a page holding six goals otherwise reads "Modifier,
              Supprimer" six times over with nothing to tell them apart.
            -->
            <div class="flex items-center gap-2 border-t border-white/10 pt-3">
                <Link
                    :href="route('goals.edit', { goal: goal.id })"
                    :dusk="`edit-goal-${goal.id}`"
                    :aria-label="`Modifier l'objectif ${goal.title}`"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange min-h-touch inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-white/20 bg-white/10 text-xs font-bold uppercase transition-colors hover:bg-white/20 focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                    Modifier
                </Link>
                <button
                    type="button"
                    :dusk="`delete-goal-${goal.id}`"
                    :aria-label="`Supprimer l'objectif ${goal.title}`"
                    class="focus-visible:ring-electric-orange min-h-touch inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-red-500/30 bg-red-500/10 text-xs font-bold text-red-600 uppercase transition-colors hover:bg-red-500/20 focus-visible:ring-2 focus-visible:outline-none dark:text-red-400"
                    @click="emit('delete', goal)"
                >
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                    Supprimer
                </button>
            </div>
        </div>
    </div>
</template>
