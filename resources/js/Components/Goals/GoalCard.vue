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
    return 'text-text-muted'
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
            class="absolute -top-2 -right-2 z-10 rotate-12 bg-green-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-lg"
        >
            COMPLÉTÉ
        </div>

        <div class="mb-4 flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-2 text-2xl">
                    {{ typeIcon }}
                </div>
                <div>
                    <h4 class="text-text-main line-clamp-1 font-bold">{{ goal.title }}</h4>
                    <span class="text-text-muted text-xs font-medium tracking-wider uppercase">{{ typeLabel }}</span>
                </div>
            </div>

            <div class="text-right">
                <div class="text-sm font-bold" :class="statusColor">{{ Math.round(progress) }}%</div>
            </div>
        </div>

        <div class="space-y-4">
            <!-- Progress Bar Container -->
            <div class="space-y-1.5">
                <div class="text-text-muted/50 flex justify-between text-[10px] font-bold tracking-widest uppercase">
                    <span>{{ goal.start_value }} {{ goal.unit }}</span>
                    <span>{{ goal.target_value }} {{ goal.unit }}</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full border border-slate-100 bg-slate-100">
                    <div
                        class="relative h-full transition-all duration-1000 ease-out"
                        :class="progressBarColor"
                        :style="{ width: progress + '%' }"
                    >
                        <div class="absolute inset-0 animate-pulse bg-linear-to-r from-transparent to-white/20"></div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">
                    <p class="text-text-muted/50 text-[10px] font-bold tracking-tight uppercase">Actuel</p>
                    <p class="text-text-main text-sm font-semibold">
                        {{ goal.current_value }} <span class="text-text-muted text-[10px]">{{ goal.unit }}</span>
                    </p>
                </div>
                <div class="rounded-lg border border-slate-100 bg-slate-50 p-2">
                    <p class="text-text-muted/50 text-[10px] font-bold tracking-tight uppercase">Cible</p>
                    <p class="text-text-main text-sm font-semibold">
                        {{ goal.target_value }} <span class="text-text-muted text-[10px]">{{ goal.unit }}</span>
                    </p>
                </div>
            </div>

            <div v-if="goal.deadline" class="text-text-muted flex items-center gap-1.5 pt-2 text-[10px] italic">
                <span>⏱️ Échéance : {{ new Date(goal.deadline).toLocaleDateString() }}</span>
            </div>
        </div>
    </GlassCard>
</template>
