<script setup>
import { ref, watch, defineAsyncComponent } from 'vue'
import axios from 'axios'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'

const OneRepMaxChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxChart.vue'))

const props = defineProps({
    exercises: Array,
})

const selectedExercise = ref(null)
const exerciseProgressData = ref([])
const loadingExercise = ref(false)
const loadFailed = ref(false)

/**
 * The catch used to log and return, which left two wrong things on screen.
 *
 * The points were only ever assigned on success, so a failed request kept the
 * previously selected exercise's curve — rendered under the new exercise's
 * name. Wrong data, presented confidently.
 *
 * And with no points at all the template fell through to "Pas assez de données
 * pour cet exercice", which is a claim about the exercise, not about the
 * request. A network failure was reported as an empty training history.
 */
const fetchExerciseProgress = async (exerciseId) => {
    if (!exerciseId) return
    loadingExercise.value = true
    loadFailed.value = false
    exerciseProgressData.value = []
    try {
        const response = await axios.get(route('stats.exercise', { exercise: exerciseId }))
        exerciseProgressData.value = response.data.progress
    } catch {
        loadFailed.value = true
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
                <GlassSelect
                    v-model="selectedExercise"
                    :options="exercises.map((ex) => ({ value: ex.id, label: ex.name }))"
                    placeholder="Sélectionner un exercice"
                    hide-label
                />
            </div>
        </div>

        <div v-if="loadingExercise" class="flex h-48 items-center justify-center">
            <div class="border-electric-orange h-8 w-8 animate-spin rounded-full border-2 border-t-transparent"></div>
        </div>
        <div
            v-else-if="loadFailed"
            class="flex h-48 flex-col items-center justify-center text-center"
            role="alert"
            dusk="exercise-progress-error"
        >
            <span class="material-symbols-outlined mb-2 text-4xl text-red-400" aria-hidden="true">cloud_off</span>
            <p class="text-text-muted mb-3 text-sm">Impossible de charger la progression.</p>
            <button
                type="button"
                @click="fetchExerciseProgress(selectedExercise)"
                class="focus-visible:ring-electric-orange text-electric-orange rounded-lg px-3 py-1 text-sm font-bold underline focus-visible:ring-2 focus-visible:outline-none"
            >
                Réessayer
            </button>
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
