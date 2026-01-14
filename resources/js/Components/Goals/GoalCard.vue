<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { computed } from 'vue'

const props = defineProps({
    goal: {
        type: Object,
        required: true,
    },
})

const progress = computed(() => props.goal.progress || 0)
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

const statusColor = computed(() => {
    if (isCompleted.value) return 'text-green-400'
    if (progress.value > 75) return 'text-blue-400'
    if (progress.value > 25) return 'text-accent-primary'
    return 'text-white/60'
})

const progressBarColor = computed(() => {
    if (isCompleted.value) return 'bg-green-500'
    return 'bg-accent-primary'
})
</script>

<template>
    <GlassCard class="group relative overflow-hidden">
        <!-- Completion Badge -->
        <div
            v-if="isCompleted"
            class="absolute -right-2 -top-2 z-10 rotate-12 bg-green-500 px-3 py-1.5 text-[10px] font-bold text-white shadow-lg"
        >
            COMPLÉTÉ
        </div>

        <div class="mb-4 flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="rounded-xl border border-white/10 bg-white/5 p-2 text-2xl">
                    {{ typeIcon }}
                </div>
                <div>
                    <h4 class="line-clamp-1 font-bold text-white">{{ goal.title }}</h4>
                    <span class="text-xs font-medium uppercase tracking-wider text-white/40">{{ typeLabel }}</span>
                </div>
            </div>

            <div class="text-right">
                <div class="text-sm font-bold" :class="statusColor">{{ Math.round(progress) }}%</div>
            </div>
        </div>

        <div class="space-y-4">
            <!-- Progress Bar Container -->
            <div class="space-y-1.5">
                <div class="flex justify-between text-[10px] font-bold uppercase tracking-widest text-white/30">
                    <span>{{ goal.start_value }} {{ goal.unit }}</span>
                    <span>{{ goal.target_value }} {{ goal.unit }}</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full border border-white/5 bg-white/5">
                    <div
                        class="relative h-full transition-all duration-1000 ease-out"
                        :class="progressBarColor"
                        :style="{ width: progress + '%' }"
                    >
                        <div class="absolute inset-0 animate-pulse bg-gradient-to-r from-transparent to-white/20"></div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-white/5 bg-white/5 p-2">
                    <p class="text-[10px] font-bold uppercase tracking-tight text-white/30">Actuel</p>
                    <p class="text-sm font-semibold text-white">
                        {{ goal.current_value }} <span class="text-[10px] text-white/40">{{ goal.unit }}</span>
                    </p>
                </div>
                <div class="rounded-lg border border-white/5 bg-white/5 p-2">
                    <p class="text-[10px] font-bold uppercase tracking-tight text-white/30">Cible</p>
                    <p class="text-sm font-semibold text-white">
                        {{ goal.target_value }} <span class="text-[10px] text-white/40">{{ goal.unit }}</span>
                    </p>
                </div>
            </div>

            <div v-if="goal.deadline" class="flex items-center gap-1.5 pt-2 text-[10px] italic text-white/40">
                <span>⏱️ Échéance : {{ new Date(goal.deadline).toLocaleDateString() }}</span>
            </div>
        </div>
    </GlassCard>
</template>
