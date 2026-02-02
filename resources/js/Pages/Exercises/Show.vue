<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link } from '@inertiajs/vue3'
import OneRepMaxChart from '@/Components/Stats/OneRepMaxChart.vue'
import { computed, defineAsyncComponent } from 'vue'

const VolumeTrendChart = defineAsyncComponent(() => import('@/Components/Stats/VolumeTrendChart.vue'))
const WeightDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/WeightDistributionChart.vue'))

const props = defineProps({
    exercise: Object,
    progress: Array,
    history: Array,
})

const volumeData = computed(() => {
    if (!props.history || props.history.length === 0) return []
    // History is desc, so reverse for chart
    return [...props.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'), // Just dd/mm
        volume: session.sets.reduce((sum, set) => sum + set.weight * set.reps, 0),
    }))
})

const weightDistributionData = computed(() => {
    if (!props.history || props.history.length === 0) return []
    const allSets = props.history.flatMap((s) => s.sets)
    if (allSets.length === 0) return []

    const weights = allSets.map((s) => parseFloat(s.weight))
    const min = Math.floor(Math.min(...weights) / 5) * 5
    const max = Math.ceil(Math.max(...weights) / 5) * 5

    const distribution = {}
    // Initialize bins
    for (let i = min; i <= max; i += 5) {
        distribution[i] = 0
    }

    weights.forEach((w) => {
        const bin = Math.floor(w / 5) * 5
        if (distribution[bin] !== undefined) {
            distribution[bin]++
        } else {
            distribution[bin] = 1
        }
    })

    return Object.entries(distribution)
        .map(([label, count]) => ({ label, count }))
        .sort((a, b) => parseFloat(a.label) - parseFloat(b.label))
})
</script>

<template>
    <Head :title="exercise.name" />

    <AuthenticatedLayout :page-title="exercise.name" show-back back-route="exercises.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('exercises.index')"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <div>
                    <h1 class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic">
                        {{ exercise.name }}
                    </h1>
                    <p class="text-text-muted text-xs font-bold tracking-wider uppercase">
                        {{ exercise.category }} •
                        {{ exercise.type === 'strength' ? 'Force' : exercise.type === 'cardio' ? 'Cardio' : 'Temps' }}
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Progress Chart -->
            <GlassCard class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Progression 1RM</h3>
                    <p class="text-text-muted text-xs font-semibold">Estimation sur 1 an</p>
                </div>
                <div v-if="progress.length > 0" class="h-64">
                    <OneRepMaxChart :data="progress" />
                </div>
                <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">show_chart</span>
                    <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
                </div>
            </GlassCard>

            <!-- Analytics Grid -->
            <div
                v-if="history.length > 0"
                class="animate-slide-up grid grid-cols-1 gap-6 md:grid-cols-2"
                style="animation-delay: 0.05s"
            >
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">Volume</h3>
                        <p class="text-text-muted text-xs font-semibold">Volume total par séance (kg)</p>
                    </div>
                    <VolumeTrendChart :data="volumeData" />
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">Charges</h3>
                        <p class="text-text-muted text-xs font-semibold">Distribution des poids utilisés</p>
                    </div>
                    <WeightDistributionChart :data="weightDistributionData" />
                </GlassCard>
            </div>

            <!-- History List -->
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Historique</h3>

                <div v-if="history.length === 0" class="py-8 text-center">
                    <p class="text-text-muted">Aucune donnée historique trouvée.</p>
                </div>

                <div v-else class="space-y-4">
                    <GlassCard v-for="session in history" :key="session.id" padding="p-0" class="overflow-hidden">
                        <!-- Header -->
                        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 p-3">
                            <div class="text-text-main font-bold">
                                {{ session.formatted_date }}
                            </div>
                            <Link
                                :href="route('workouts.show', { workout: session.workout_id })"
                                class="text-electric-orange text-xs font-bold tracking-wider uppercase hover:underline"
                            >
                                {{ session.workout_name }}
                            </Link>
                        </div>

                        <!-- Sets -->
                        <div class="space-y-2 p-3">
                            <div
                                v-for="(set, index) in session.sets"
                                :key="index"
                                class="flex items-center justify-between text-sm"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="text-text-muted w-4 font-mono text-xs">{{ index + 1 }}</span>
                                    <span class="text-text-main font-bold"
                                        >{{ set.weight }}
                                        <span class="text-text-muted text-xs font-normal">kg</span></span
                                    >
                                    <span class="text-text-muted">x</span>
                                    <span class="text-text-main font-bold"
                                        >{{ set.reps }}
                                        <span class="text-text-muted text-xs font-normal">reps</span></span
                                    >
                                </div>
                                <div class="text-text-muted text-xs font-semibold">
                                    1RM: {{ Math.round(set['1rm']) }}
                                </div>
                            </div>
                        </div>

                        <!-- Footer Best 1RM -->
                        <div
                            class="text-text-muted border-t border-slate-100 bg-slate-50/30 p-2 text-center text-xs font-medium"
                        >
                            Meilleur 1RM estimé:
                            <span class="text-text-main font-bold">{{ Math.round(session.best_1rm) }} kg</span>
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
