<script setup>
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
    if (isCompleted.value) return 'text-green-300'
    if (progress.value > 75) return 'text-blue-200'
    if (progress.value > 25) return 'text-white'
    return 'text-white/80'
})

const progressBarColor = computed(() => {
    if (isCompleted.value) return 'bg-green-400'
    return 'bg-white'
})
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-3xl border border-white/20 bg-linear-to-br from-orange-500 via-pink-500 to-violet-600 p-6 shadow-xl transition-all duration-300 hover:scale-[1.02] hover:shadow-2xl hover:shadow-orange-500/20"
    >
        <!-- Glass Overlay -->
        <div class="absolute inset-0 bg-white/10 backdrop-blur-md transition-opacity group-hover:bg-white/15"></div>

        <!-- Content -->
        <div class="relative z-10">
            <!-- Completion Badge -->
            <div
                v-if="isCompleted"
                class="absolute -top-2 -right-2 rotate-12 bg-green-500 px-3 py-1.5 text-[10px] font-bold text-white shadow-lg"
            >
                COMPLÉTÉ
            </div>

            <div class="mb-4 flex items-start justify-between">
                <div class="flex items-center gap-3">
                    <div
                        class="rounded-xl border border-white/20 bg-white/10 p-2 text-2xl shadow-sm transition-colors group-hover:bg-white/20"
                    >
                        {{ typeIcon }}
                    </div>
                    <div>
                        <h4 class="line-clamp-1 font-bold text-white shadow-black/10 drop-shadow-sm">
                            {{ goal.title }}
                        </h4>
                        <span class="text-xs font-medium tracking-wider text-white/80 uppercase">{{ typeLabel }}</span>
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-sm font-bold" :class="statusColor">{{ Math.round(progress) }}%</div>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Progress Bar Container -->
                <div class="space-y-1.5">
                    <div class="flex justify-between text-[10px] font-bold tracking-widest text-white/60 uppercase">
                        <span>{{ goal.start_value }} {{ goal.unit }}</span>
                        <span>{{ goal.target_value }} {{ goal.unit }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full border border-white/10 bg-black/20">
                        <div
                            class="relative h-full transition-all duration-1000 ease-out"
                            :class="progressBarColor"
                            :style="{ width: progress + '%' }"
                        >
                            <div
                                class="absolute inset-0 animate-pulse bg-linear-to-r from-transparent to-white/40"
                            ></div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-lg border border-white/10 bg-white/5 p-2 transition-colors hover:bg-white/10">
                        <p class="text-[10px] font-bold tracking-tight text-white/60 uppercase">Actuel</p>
                        <p class="text-sm font-semibold text-white">
                            {{ goal.current_value }} <span class="text-[10px] text-white/80">{{ goal.unit }}</span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-white/10 bg-white/5 p-2 transition-colors hover:bg-white/10">
                        <p class="text-[10px] font-bold tracking-tight text-white/60 uppercase">Cible</p>
                        <p class="text-sm font-semibold text-white">
                            {{ goal.target_value }} <span class="text-[10px] text-white/80">{{ goal.unit }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="goal.deadline" class="flex items-center gap-1.5 pt-2 text-[10px] text-white/80 italic">
                    <span>⏱️ Échéance : {{ new Date(goal.deadline).toLocaleDateString() }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
