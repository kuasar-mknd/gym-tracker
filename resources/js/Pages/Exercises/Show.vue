<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link } from '@inertiajs/vue3'
import OneRepMaxChart from '@/Components/Stats/OneRepMaxChart.vue'

const props = defineProps({
    exercise: Object,
    progress: Array,
    history: Array,
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
                        {{ exercise.category }} • {{ exercise.type === 'strength' ? 'Force' : exercise.type === 'cardio' ? 'Cardio' : 'Temps' }}
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Progress Chart -->
            <GlassCard class="animate-slide-up">
                 <div class="mb-4">
                    <h3 class="font-display text-lg font-black uppercase italic text-text-main">Progression 1RM</h3>
                    <p class="text-xs font-semibold text-text-muted">Estimation sur 1 an</p>
                </div>
                <div v-if="progress.length > 0" class="h-64">
                    <OneRepMaxChart :data="progress" />
                </div>
                <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined mb-2 text-5xl text-text-muted/30">show_chart</span>
                    <p class="text-sm text-text-muted">Pas assez de données pour afficher le graphique</p>
                </div>
            </GlassCard>

            <!-- History List -->
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="font-display mb-4 text-lg font-black uppercase italic text-text-main">Historique</h3>

                <div v-if="history.length === 0" class="text-center py-8">
                     <p class="text-text-muted">Aucune donnée historique trouvée.</p>
                </div>

                <div v-else class="space-y-4">
                    <GlassCard v-for="session in history" :key="session.id" padding="p-0" class="overflow-hidden">
                        <!-- Header -->
                        <div class="bg-slate-50/50 p-3 border-b border-slate-100 flex justify-between items-center">
                            <div class="font-bold text-text-main">
                                {{ session.formatted_date }}
                            </div>
                            <Link :href="route('workouts.show', {workout: session.workout_id})" class="text-xs text-electric-orange hover:underline font-bold uppercase tracking-wider">
                                {{ session.workout_name }}
                            </Link>
                        </div>

                        <!-- Sets -->
                        <div class="p-3 space-y-2">
                             <div v-for="(set, index) in session.sets" :key="index" class="flex justify-between items-center text-sm">
                                <div class="flex items-center gap-3">
                                    <span class="text-text-muted font-mono text-xs w-4">{{ index + 1 }}</span>
                                    <span class="font-bold text-text-main">{{ set.weight }} <span class="text-xs text-text-muted font-normal">kg</span></span>
                                    <span class="text-text-muted">x</span>
                                    <span class="font-bold text-text-main">{{ set.reps }} <span class="text-xs text-text-muted font-normal">reps</span></span>
                                </div>
                                <div class="text-xs font-semibold text-text-muted">
                                    1RM: {{ Math.round(set['1rm']) }}
                                </div>
                             </div>
                        </div>

                        <!-- Footer Best 1RM -->
                        <div class="bg-slate-50/30 p-2 text-center border-t border-slate-100 text-xs text-text-muted font-medium">
                            Meilleur 1RM estimé: <span class="font-bold text-text-main">{{ Math.round(session.best_1rm) }} kg</span>
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
