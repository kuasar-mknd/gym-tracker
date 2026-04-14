<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import GoalCard from '@/Components/Goals/GoalCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { ref, watch, computed, defineAsyncComponent } from 'vue'

const GoalTypeChart = defineAsyncComponent(() => import('@/Components/Stats/GoalTypeChart.vue'))

const props = defineProps({
    goals: Array,
    exercises: Array,
    measurementTypes: Array,
})

const showCreateForm = ref(false)

const form = useForm({
    title: '',
    type: 'weight',
    target_value: '',
    exercise_id: '',
    measurement_type: '',
    deadline: '',
    start_value: '',
})

const submit = () => {
    form.post(route('goals.store'), {
        onSuccess: () => {
            showCreateForm.value = false
            form.reset()
        },
    })
}

// Auto-fill title based on selection
watch(
    () => [form.type, form.exercise_id, form.measurement_type],
    () => {
        if (form.type === 'weight' && form.exercise_id) {
            const ex = props.exercises.find((e) => e.id == form.exercise_id)
            if (ex) form.title = `Soulever ${form.target_value || '?'} kg au ${ex.name}`
        } else if (form.type === 'measurement' && form.measurement_type) {
            const mt = props.measurementTypes.find((m) => m.value === form.measurement_type)
            if (mt)
                form.title = `Atteindre ${form.target_value || '?'} ${form.measurement_type === 'body_fat' ? '%' : 'cm'} de ${mt.label}`
        } else if (form.type === 'frequency') {
            form.title = `Atteindre ${form.target_value || '?'} séances au total`
        }
    },
)

const activeGoals = computed(() => props.goals.filter((g) => !g.completed_at))
const completedGoals = computed(() => props.goals.filter((g) => g.completed_at))

const goalDistribution = computed(() => {
    const types = {
        weight: { label: 'Force', count: 0 },
        frequency: { label: 'Fréquence', count: 0 },
        volume: { label: 'Volume', count: 0 },
        measurement: { label: 'Mesure', count: 0 },
    }

    props.goals.forEach((goal) => {
        if (types[goal.type]) {
            types[goal.type].count++
        }
    })

    return Object.values(types).filter((t) => t.count > 0)
})

const goalTypeOptions = [
    { value: 'weight', label: 'Force (Poids max)' },
    { value: 'frequency', label: 'Fréquence (Séances)' },
    { value: 'volume', label: 'Volume (Max par séance)' },
    { value: 'measurement', label: 'Mensuration' },
]
</script>

<template>
    <Head title="Mes Objectifs" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-text-main text-2xl font-bold tracking-tight">Mes Objectifs 🎯</h2>
                    <p class="text-text-muted text-sm">Fixe tes cibles et dépasse tes limites.</p>
                </div>
                <GlassButton @click="showCreateForm = !showCreateForm">
                    {{ showCreateForm ? 'Annuler' : 'Nouvel Objectif' }}
                </GlassButton>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-8 sm:px-6 lg:px-8">
                <!-- Stats Section -->
                <div v-if="goalDistribution.length > 0" class="animate-slide-up">
                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Répartition</h3>
                            <p class="text-text-muted text-xs font-semibold">Type d'objectifs</p>
                        </div>
                        <GoalTypeChart :data="goalDistribution" />
                    </GlassCard>
                </div>

                <!-- Create Form -->
                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="transform scale-95 opacity-0 -translate-y-4"
                    enter-to-class="transform scale-100 opacity-100 translate-y-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="transform scale-100 opacity-100 translate-y-0"
                    leave-to-class="transform scale-95 opacity-0 -translate-y-4"
                >
                    <div v-if="showCreateForm">
                        <GlassCard class="p-6">
                            <h3 class="text-text-main mb-6 text-lg font-bold">Nouvel Objectif</h3>
                            <form @submit.prevent="submit" class="space-y-6">
                                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <div class="space-y-4">
                                        <GlassSelect
                                            v-model="form.type"
                                            label="Type d'objectif"
                                            :options="goalTypeOptions"
                                            :error="form.errors.type"
                                        />

                                        <GlassSelect
                                            v-if="form.type === 'weight' || form.type === 'volume'"
                                            v-model="form.exercise_id"
                                            label="Exercice"
                                            :options="exercises.map((e) => ({ value: e.id, label: e.name }))"
                                            placeholder="Sélectionner un exercice"
                                            :error="form.errors.exercise_id"
                                        />

                                        <GlassSelect
                                            v-if="form.type === 'measurement'"
                                            v-model="form.measurement_type"
                                            label="Mensuration"
                                            :options="measurementTypes"
                                            placeholder="Sélectionner une mesure"
                                            :error="form.errors.measurement_type"
                                        />
                                    </div>

                                    <div class="space-y-4">
                                        <GlassInput
                                            label="Valeur Cible"
                                            v-model="form.target_value"
                                            type="number"
                                            step="0.1"
                                            required
                                        />
                                        <GlassInput
                                            label="Valeur de Départ (Optionnel)"
                                            v-model="form.start_value"
                                            type="number"
                                            step="0.1"
                                            placeholder="0"
                                        />
                                        <GlassInput
                                            label="Titre du défi"
                                            v-model="form.title"
                                            placeholder="Ex: Bench Press 100kg"
                                            required
                                        />
                                    </div>
                                </div>

                                <div class="flex justify-end gap-3 border-t border-white/5 pt-4">
                                    <GlassButton type="button" @click="showCreateForm = false" variant="secondary"
                                        >Annuler</GlassButton
                                    >
                                    <GlassButton type="submit" :loading="form.processing">Créer l'objectif</GlassButton>
                                </div>
                            </form>
                        </GlassCard>
                    </div>
                </Transition>

                <!-- Active Goals -->
                <div class="space-y-4">
                    <h3 class="text-text-main flex items-center gap-2 text-lg font-bold">
                        En cours ⚡
                        <span class="text-text-muted text-xs font-normal">({{ activeGoals.length }})</span>
                    </h3>

                    <div
                        v-if="activeGoals.length === 0 && !showCreateForm"
                        class="rounded-3xl border border-dashed border-slate-200 bg-white/30 p-6 py-12 text-center"
                    >
                        <p class="text-text-muted italic">
                            Aucun objectif actif pour le moment. C'est le moment d'en fixer un !
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                        <GoalCard v-for="goal in activeGoals" :key="goal.id" :goal="goal" />
                    </div>
                </div>

                <!-- Completed Goals -->
                <div v-if="completedGoals.length > 0" class="space-y-4 opacity-70">
                    <h3 class="text-text-main flex items-center gap-2 text-lg font-bold">
                        Accomplis 🏆
                        <span class="text-text-muted text-xs font-normal">({{ completedGoals.length }})</span>
                    </h3>
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                        <GoalCard v-for="goal in completedGoals" :key="goal.id" :goal="goal" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
