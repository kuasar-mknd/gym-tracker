<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link } from '@inertiajs/vue3'
import OneRepMaxChart from '@/Components/Stats/OneRepMaxChart.vue'

const props = defineProps({
    exercise: Object,
    history: Array,
})
</script>

<template>
    <Head :title="exercise.name" />

    <AuthenticatedLayout liquid-variant="subtle">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('exercises.index')"
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors hover:text-electric-orange"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-xl font-semibold text-text-main">{{ exercise.name }}</h2>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Header Info -->
            <GlassCard padding="p-6">
                <div class="flex items-center gap-4">
                     <div
                        class="flex size-16 items-center justify-center rounded-2xl bg-electric-orange/10 text-electric-orange"
                    >
                        <span class="material-symbols-outlined text-4xl">fitness_center</span>
                    </div>
                    <div>
                        <h1 class="font-display text-2xl font-black uppercase italic text-text-main">
                            {{ exercise.name }}
                        </h1>
                        <div class="mt-1 flex gap-2">
                             <span class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-500">
                                {{ exercise.category || 'Non classé' }}
                            </span>
                             <span class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-500">
                                {{ exercise.type }}
                            </span>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- 1RM History Chart -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="mb-4">
                    <h3 class="font-display text-lg font-black uppercase italic text-text-main">
                        Progression 1RM
                    </h3>
                    <p class="text-xs font-semibold text-text-muted">Estimé sur les 12 derniers mois</p>
                </div>

                <div v-if="history && history.length > 0">
                    <OneRepMaxChart :data="history" />
                </div>
                <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined mb-2 text-4xl text-text-muted/30">show_chart</span>
                    <p class="text-sm text-text-muted">Pas assez de données pour afficher le graphique.</p>
                    <p class="mt-1 text-xs text-text-muted/70">Effectue cet exercice dans tes séances pour voir ta progression !</p>
                </div>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>
