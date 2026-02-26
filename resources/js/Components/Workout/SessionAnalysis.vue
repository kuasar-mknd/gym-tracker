<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { computed, defineAsyncComponent } from 'vue'

const SessionVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/SessionVolumeChart.vue'))
const MuscleDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/MuscleDistributionChart.vue'))

const props = defineProps({
    workout: {
        type: Object,
        required: true,
    },
})

const exerciseVolume = computed(() => {
    if (!props.workout?.workout_lines) return []

    return props.workout.workout_lines
        .map((line) => {
            const volume = line.sets.reduce((sum, set) => {
                // Only count completed sets? Or all sets if workout is finished?
                // Usually completed sets matter most, but if workout is finished, maybe all?
                // Let's stick to what's in the sets, assuming uncompleted sets might have 0 reps or weight if skipped,
                // or user wants to see what they logged.
                // However, logic usually implies weight * reps.
                return sum + (parseFloat(set.weight) || 0) * (parseFloat(set.reps) || 0)
            }, 0)

            return {
                name: line.exercise.name,
                volume: volume,
            }
        })
        .filter((item) => item.volume > 0)
})

const muscleDistribution = computed(() => {
    if (!props.workout?.workout_lines) return []

    const dist = {}
    props.workout.workout_lines.forEach((line) => {
        const cat = line.exercise.category || 'Autre'
        const volume = line.sets.reduce((sum, set) => {
            return sum + (parseFloat(set.weight) || 0) * (parseFloat(set.reps) || 0)
        }, 0)

        if (volume > 0) {
            dist[cat] = (dist[cat] || 0) + volume
        }
    })

    return Object.entries(dist)
        .map(([category, volume]) => ({ category, volume }))
        .sort((a, b) => b.volume - a.volume)
})

const totalVolume = computed(() => {
    return exerciseVolume.value.reduce((acc, curr) => acc + curr.volume, 0)
})
</script>

<template>
    <div class="animate-slide-up space-y-6">
        <div class="flex items-center justify-between px-1">
            <h2 class="font-display text-text-main text-lg font-black uppercase italic">Analyse de la séance</h2>
            <div class="text-right">
                <span class="text-text-muted text-[10px] font-black tracking-wider uppercase">Volume Total</span>
                <div class="font-display text-electric-orange text-xl font-black">
                    {{ totalVolume.toLocaleString() }} <span class="text-text-muted text-sm">kg</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Muscle Distribution -->
            <GlassCard>
                <div class="mb-4">
                    <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Répartition</h3>
                    <p class="font-display text-text-main text-lg font-black uppercase italic">Groupes Musculaires</p>
                </div>
                <div v-if="muscleDistribution.length > 0" class="h-48">
                    <MuscleDistributionChart :data="muscleDistribution" />
                </div>
                <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">pie_chart</span>
                    <p class="text-text-muted text-sm">Données insuffisantes</p>
                </div>
            </GlassCard>

            <!-- Volume per Exercise -->
            <GlassCard>
                <div class="mb-4">
                    <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Volume</h3>
                    <p class="font-display text-text-main text-lg font-black uppercase italic">Par Exercice</p>
                </div>
                <div v-if="exerciseVolume.length > 0" class="h-48">
                    <SessionVolumeChart :data="exerciseVolume" />
                </div>
                <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">bar_chart</span>
                    <p class="text-text-muted text-sm">Données insuffisantes</p>
                </div>
            </GlassCard>
        </div>
    </div>
</template>
