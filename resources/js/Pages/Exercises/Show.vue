<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import ExerciseProgressChart from '@/Components/Stats/ExerciseProgressChart.vue'
import { Head, Link } from '@inertiajs/vue3'

const props = defineProps({
    exercise: Object,
    progress: Array,
})
</script>

<template>
    <Head :title="exercise.name" />

    <AuthenticatedLayout :page-title="exercise.name">
        <template #header-actions>
            <Link :href="route('exercises.index')">
                <GlassButton size="sm" variant="secondary"> Retour </GlassButton>
            </Link>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-text-main text-xl font-semibold">{{ exercise.name }}</h2>
                    <p class="text-text-muted text-sm">{{ exercise.category }}</p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Chart -->
            <GlassCard class="animate-slide-up">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-display text-xs font-black tracking-[0.2em] text-amber-500 uppercase">
                        Progression 1RM
                    </h3>
                    <div v-if="progress.length > 0" class="text-text-main text-sm font-bold">
                        {{ progress[progress.length - 1].one_rep_max }} kg
                    </div>
                </div>

                <div v-if="progress.length > 1" class="h-64">
                    <ExerciseProgressChart :data="progress" />
                </div>
                <div v-else class="text-text-muted/50 flex h-32 items-center justify-center font-medium">
                    Pas assez de données pour afficher le graphique
                </div>
            </GlassCard>

            <!-- History List -->
            <div class="animate-slide-up space-y-2" style="animation-delay: 0.1s">
                <h3 class="font-display text-text-muted mb-2 text-xs font-black tracking-[0.2em] uppercase">
                    Historique Récent
                </h3>

                <div v-if="progress.length === 0">
                    <GlassCard>
                        <div class="py-8 text-center">
                            <p class="text-text-muted">Aucune performance enregistrée</p>
                        </div>
                    </GlassCard>
                </div>

                <GlassCard
                    v-for="item in [...progress].reverse().slice(0, 10)"
                    :key="item.date"
                    padding="p-4"
                    class="group"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-text-main text-xl font-bold">{{ item.one_rep_max }} kg</span>
                                <span class="text-xs font-bold tracking-wider text-amber-500 uppercase">1RM Est.</span>
                            </div>
                            <div class="text-text-muted text-sm font-medium">
                                {{ item.full_date }}
                            </div>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
