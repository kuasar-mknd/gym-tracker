<script setup>
import { ref, watch, defineAsyncComponent } from 'vue'
import axios from 'axios'
import GlassCard from '@/Components/UI/GlassCard.vue'

const OneRepMaxChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxChart.vue'))

const props = defineProps({
    exercises: Array,
})

const selectedExercise = ref(null)
const exerciseProgressData = ref([])
const loadingExercise = ref(false)

const fetchExerciseProgress = async (exerciseId) => {
    if (!exerciseId) return
    loadingExercise.value = true
    try {
        const response = await axios.get(route('stats.exercise', { exercise: exerciseId }))
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
    <!-- Exercise Progress (1RM) -->
    <GlassCard>
        <div class="mb-4">
            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Progression 1RM</h3>
            <div class="mt-3">
                <select v-model="selectedExercise" class="glass-input w-full">
                    <option :value="null" disabled>Sélectionner un exercice</option>
                    <option v-for="ex in exercises" :key="ex.id" :value="ex.id">
                        {{ ex.name }}
                    </option>
                </select>
            </div>
        </div>

        <div v-if="loadingExercise" class="flex h-48 items-center justify-center">
            <div class="border-electric-orange h-8 w-8 animate-spin rounded-full border-2 border-t-transparent"></div>
        </div>
        <div v-else-if="selectedExercise && exerciseProgressData.length > 0" class="h-48">
            <OneRepMaxChart :data="exerciseProgressData" />
        </div>
        <div v-else-if="selectedExercise" class="flex h-48 flex-col items-center justify-center text-center">
            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">trending_up</span>
            <p class="text-text-muted text-sm">Pas assez de données pour cet exercice</p>
        </div>
        <div v-else class="flex h-48 flex-col items-center justify-center text-center">
            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">fitness_center</span>
            <p class="text-text-muted text-sm">Choisis un exercice pour voir ton évolution</p>
        </div>
    </GlassCard>
</template>
