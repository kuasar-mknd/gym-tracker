<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import axios from 'axios'
import MuscleDistributionChart from '@/Components/Stats/MuscleDistributionChart.vue'
import VolumeTrendChart from '@/Components/Stats/VolumeTrendChart.vue'
import OneRepMaxChart from '@/Components/Stats/OneRepMaxChart.vue'

const props = defineProps({
    volumeTrend: Array,
    muscleDistribution: Array,
    monthlyComparison: Object,
    exercises: Array,
})

const selectedExercise = ref(null)
const exerciseProgressData = ref([])
const loadingExercise = ref(false)

const fetchExerciseProgress = async (exerciseId) => {
    if (!exerciseId) return
    loadingExercise.value = true
    try {
        const response = await axios.get(route('stats.exercise', exerciseId))
        exerciseProgressData.value = response.data.progress
    } catch (error) {
        console.error('Error fetching exercise progress:', error)
    } finally {
        loadingExercise.value = false
    }
}

watch(selectedExercise, (newVal) => {
    if (newVal) {
        fetchExerciseProgress(newVal)
    }
})
</script>

<template>
    <Head title="Statistiques" />

    <AuthenticatedLayout page-title="Statistiques">
        <div class="space-y-6">
            <!-- Volume Trend -->
            <GlassCard>
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Évolution du Volume</h3>
                        <p class="text-xs text-white/50">30 derniers jours</p>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-bold uppercase tracking-wider text-white/40">Total</div>
                        <div class="text-xl font-black text-accent-primary">
                            {{ volumeTrend.reduce((acc, curr) => acc + curr.volume, 0).toLocaleString() }}
                            <span class="text-xs">kg</span>
                        </div>
                    </div>
                </div>
                <div v-if="volumeTrend.length > 0">
                    <VolumeTrendChart :data="volumeTrend" />
                </div>
                <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                    <div class="mb-2 text-3xl">📭</div>
                    <p class="text-sm text-white/50">Pas encore de données de volume</p>
                </div>
            </GlassCard>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Muscle Distribution -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="text-lg font-bold text-white">Répartition par Muscle</h3>
                        <p class="text-xs text-white/50">Volume par catégorie d'exercice</p>
                    </div>
                    <div v-if="muscleDistribution.length > 0">
                        <MuscleDistributionChart :data="muscleDistribution" />
                    </div>
                    <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                        <div class="mb-2 text-3xl">🍕</div>
                        <p class="text-sm text-white/50">Données de répartition indisponibles</p>
                    </div>
                </GlassCard>

                <!-- Exercise Progress (1RM) -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="text-lg font-bold text-white">Progression 1RM</h3>
                        <div class="mt-2">
                            <select
                                v-model="selectedExercise"
                                class="w-full rounded-xl border-none bg-white/5 py-2 pl-3 pr-10 text-sm text-white focus:ring-2 focus:ring-accent-primary"
                            >
                                <option :value="null" disabled>Sélectionner un exercice</option>
                                <option v-for="ex in exercises" :key="ex.id" :value="ex.id">
                                    {{ ex.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div v-if="loadingExercise" class="flex h-48 items-center justify-center sm:h-64">
                        <div
                            class="h-8 w-8 animate-spin rounded-full border-2 border-accent-primary border-t-transparent"
                        ></div>
                    </div>
                    <div v-else-if="selectedExercise && exerciseProgressData.length > 0">
                        <OneRepMaxChart :data="exerciseProgressData" />
                    </div>
                    <div
                        v-else-if="selectedExercise"
                        class="flex h-48 flex-col items-center justify-center text-center sm:h-64"
                    >
                        <div class="mb-2 text-2xl">📈</div>
                        <p class="text-sm text-white/50">Pas assez de données pour cet exercice</p>
                    </div>
                    <div v-else class="flex h-48 flex-col items-center justify-center text-center sm:h-64">
                        <div class="mb-2 text-2xl">🏋️‍♂️</div>
                        <p class="text-sm text-white/50">Choisis un exercice pour voir ton évolution</p>
                    </div>
                </GlassCard>
            </div>

            <!-- Summary Stats -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-xs font-bold uppercase tracking-wider text-white/40">Séances</div>
                    <div class="mt-1 text-2xl font-black text-white">
                        {{ volumeTrend.length }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-xs font-bold uppercase tracking-wider text-white/40">Muscles</div>
                    <div class="mt-1 text-2xl font-black text-white">
                        {{ muscleDistribution.length }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-xs font-bold uppercase tracking-wider text-white/40">Ce Mois</div>
                    <div class="mt-1 text-2xl font-black text-white">
                        {{ Math.round(monthlyComparison.current_month_volume).toLocaleString() }}
                        <span class="text-xs">kg</span>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-xs font-bold uppercase tracking-wider text-white/40">vs Mois Dernier</div>
                    <div
                        class="mt-1 text-2xl font-black"
                        :class="monthlyComparison.percentage >= 0 ? 'text-accent-primary' : 'text-accent-warning'"
                    >
                        {{ monthlyComparison.percentage >= 0 ? '+' : '' }}{{ monthlyComparison.percentage }}%
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 1rem;
}
</style>
