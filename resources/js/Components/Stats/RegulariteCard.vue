<script setup>
import { Deferred } from '@inertiajs/vue3'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const WorkoutsPerMonthChart = graphiqueAsynchrone(() => import('@/Components/Stats/WorkoutsPerMonthChart.vue'))
const WorkoutFrequencyChart = graphiqueAsynchrone(() => import('@/Components/Stats/WorkoutFrequencyChart.vue'))

defineProps({
    regularite: Object,
})
</script>

<template>
    <GlassCard>
        <div class="mb-4">
            <h3 class="titre-plat">Régularité</h3>
            <p class="text-text-muted text-xs">Séances par mois et par jour de la semaine, 6 derniers mois</p>
        </div>
        <Deferred data="deferredData">
            <template #rescue>
                <IndisponibleHorsLigne />
            </template>
            <template #fallback>
                <GlassSkeleton height="12rem" width="100%" class="rounded-xl" />
            </template>
            <div v-if="regularite" class="space-y-6">
                <div>
                    <h4 class="etiquette mb-2">Par mois</h4>
                    <WorkoutsPerMonthChart :data="regularite.monthly_frequency" />
                </div>
                <div>
                    <h4 class="etiquette mb-2">Par jour</h4>
                    <div class="h-48 w-full">
                        <WorkoutFrequencyChart :data="regularite.day_of_week_frequency" />
                    </div>
                </div>
            </div>
        </Deferred>
    </GlassCard>
</template>
