<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import GoalCard from '@/Components/Goals/GoalCard.vue'
import GoalForm from '@/Components/Goals/GoalForm.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import Modal from '@/Components/UI/Modal.vue'
import { ref, computed, defineAsyncComponent } from 'vue'

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

/**
 * Deleting a goal throws away its history, so it goes through a confirmation
 * dialog — Modal renders a native <dialog> opened with showModal(), which is
 * what supplies the focus trap and Escape-to-close.
 */
const goalPendingDeletion = ref(null)
const deleteForm = useForm({})

const confirmDeletion = (goal) => {
    goalPendingDeletion.value = goal
}

const closeDeletion = () => {
    goalPendingDeletion.value = null
}

const deleteGoal = () => {
    if (!goalPendingDeletion.value) {
        return
    }

    deleteForm.delete(route('goals.destroy', { goal: goalPendingDeletion.value.id }), {
        preserveScroll: true,
        onSuccess: () => closeDeletion(),
    })
}

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
</script>

<template>
    <Head title="Mes Objectifs" />

    <AuthenticatedLayout page-title="Mes Objectifs">
        <!-- The #header slot is desktop-only (hidden sm:block in the layout), so the
             create action has to be mirrored here or it is unreachable on phones. -->
        <template #header-actions>
            <GlassButton
                size="sm"
                dusk="create-goal-btn"
                :aria-label="showCreateForm ? 'Annuler la création d\'objectif' : 'Nouvel objectif'"
                @click="showCreateForm = !showCreateForm"
            >
                <span class="material-symbols-outlined text-sm" aria-hidden="true">
                    {{ showCreateForm ? 'close' : 'add' }}
                </span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-text-main text-2xl font-bold tracking-tight">Mes Objectifs 🎯</h2>
                    <p class="text-text-muted text-sm">Fixe tes cibles et dépasse tes limites.</p>
                </div>
                <GlassButton dusk="create-goal-btn-desktop" @click="showCreateForm = !showCreateForm">
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
                            <div dusk="goal-create-form">
                                <GoalForm
                                    :form="form"
                                    :exercises="exercises"
                                    :measurement-types="measurementTypes"
                                    submit-label="Créer l'objectif"
                                    auto-title
                                    @submit="submit"
                                    @cancel="showCreateForm = false"
                                />
                            </div>
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
                        <GoalCard v-for="goal in activeGoals" :key="goal.id" :goal="goal" @delete="confirmDeletion" />
                    </div>
                </div>

                <!-- Completed Goals -->
                <div v-if="completedGoals.length > 0" class="space-y-4 opacity-70">
                    <h3 class="text-text-main flex items-center gap-2 text-lg font-bold">
                        Accomplis 🏆
                        <span class="text-text-muted text-xs font-normal">({{ completedGoals.length }})</span>
                    </h3>
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                        <GoalCard
                            v-for="goal in completedGoals"
                            :key="goal.id"
                            :goal="goal"
                            @delete="confirmDeletion"
                        />
                    </div>
                </div>
            </div>
        </div>

        <Modal
            :show="goalPendingDeletion !== null"
            max-width="md"
            aria-labelledby="delete-goal-title"
            @close="closeDeletion"
        >
            <div class="p-6">
                <h2 id="delete-goal-title" class="text-text-main text-lg font-semibold">Supprimer cet objectif ?</h2>
                <p class="text-text-muted mt-2 text-sm">
                    « {{ goalPendingDeletion?.title }} » sera définitivement effacé, avec sa progression.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <GlassButton variant="secondary" @click="closeDeletion">Annuler</GlassButton>
                    <GlassButton
                        variant="danger"
                        dusk="confirm-delete-goal"
                        :loading="deleteForm.processing"
                        @click="deleteGoal"
                    >
                        Supprimer
                    </GlassButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
