<script setup>
import { ref, watch, defineAsyncComponent } from 'vue'
import { http } from '@/Utils/http'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const OneRepMaxChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxChart.vue'))

defineProps({
    exercises: Array,
})

const selectedExercise = ref(null)
const exerciseProgressData = ref([])
const loadingExercise = ref(false)
const loadFailed = ref(false)

/**
 * Loads one exercise's curve, and applies an answer only while it is still the
 * answer to the question being asked.
 *
 * Two ways this went wrong, fixed at different times.
 *
 * The catch used to log and return. Points were assigned on success only, so a
 * failed request kept the previously selected exercise's curve — rendered under
 * the new exercise's name. And with no points the template fell through to
 * "Pas assez de données pour cet exercice", a claim about the exercise rather
 * than about the request: a network failure reported as an empty history.
 *
 * The second is that nothing sequenced the requests. Pick one exercise, pick
 * another before the first has replied, and whichever response arrived last
 * won — so a slow request could paint its curve under the name of the exercise
 * now selected. The chart looked ordinary; only the numbers were someone
 * else's.
 *
 * Comparing against `selectedExercise` rather than counting requests is what
 * makes it right for the case that matters: coming back to an exercise while
 * its own earlier request is still in flight, where a sequence number would
 * discard an answer that happens to be correct.
 */
const fetchExerciseProgress = async (exerciseId) => {
    if (!exerciseId) return
    loadingExercise.value = true
    loadFailed.value = false
    exerciseProgressData.value = []
    try {
        const response = await http.get(route('stats.exercise', { exercise: exerciseId }))

        if (exerciseId !== selectedExercise.value) {
            return
        }

        exerciseProgressData.value = response.data.progress
    } catch {
        if (exerciseId !== selectedExercise.value) {
            return
        }

        loadFailed.value = true
    } finally {
        // The spinner belongs to the current request only; an overtaken one
        // clearing it would announce a load that has not finished.
        if (exerciseId === selectedExercise.value) {
            loadingExercise.value = false
        }
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
            <h3 class="titre-carte">Progression 1RM</h3>
            <div class="mt-3">
                <GlassSelect
                    v-model="selectedExercise"
                    :options="exercises.map((ex) => ({ value: ex.id, label: ex.name }))"
                    label="Exercice à afficher"
                    placeholder="Sélectionner un exercice"
                    hide-label
                />
            </div>
        </div>

        <div v-if="loadingExercise" class="flex h-48 items-center justify-center">
            <div class="border-accent-primary h-8 w-8 animate-spin rounded-full border-2 border-t-transparent"></div>
        </div>
        <div
            v-else-if="loadFailed"
            class="flex h-48 flex-col items-center justify-center text-center"
            role="alert"
            dusk="exercise-progress-error"
        >
            <GlassIcon name="cloud_off" size="xl" class="text-accent-danger-deep mb-2" />
            <p class="text-text-muted mb-3 text-sm">Impossible de charger la progression.</p>
            <GlassButton variant="ghost" size="sm" icon="refresh" @click="fetchExerciseProgress(selectedExercise)">
                Réessayer
            </GlassButton>
        </div>
        <div v-else-if="selectedExercise && exerciseProgressData.length > 0" class="h-48">
            <OneRepMaxChart :data="exerciseProgressData" />
        </div>
        <div v-else-if="selectedExercise" class="h-48">
            <GlassEmptyState taille="ligne" icon="trending_up" title="Pas assez de données pour cet exercice" />
        </div>
        <div v-else class="h-48">
            <GlassEmptyState taille="ligne" icon="fitness_center" title="Choisis un exercice pour voir ton évolution" />
        </div>
    </GlassCard>
</template>
