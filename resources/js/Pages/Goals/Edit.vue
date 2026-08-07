<script setup>
/**
 * Goals Edit Page
 *
 * A goal could be created and then never touched again: the update and destroy
 * routes existed and worked, but nothing in the interface reached them. This is
 * the missing half.
 *
 * The fields come from GoalForm, shared with the create form on the index, so
 * the two screens cannot drift apart.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GoalForm from '@/Components/Goals/GoalForm.vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'

const props = defineProps({
    goal: { type: Object, required: true },
    exercises: { type: Array, required: true },
    measurementTypes: { type: Array, required: true },
})

const form = useForm({
    title: props.goal.title,
    type: props.goal.type,
    target_value: props.goal.target_value,
    exercise_id: props.goal.exercise_id,
    measurement_type: props.goal.measurement_type,
    deadline: props.goal.deadline,
    start_value: props.goal.start_value,
})

const submit = () => {
    form.put(route('goals.update', { goal: props.goal.id }))
}

const cancel = () => {
    router.get(route('goals.index'))
}
</script>

<template>
    <Head title="Modifier l'objectif" />

    <AuthenticatedLayout page-title="Modifier l'objectif">
        <template #header>
            <div>
                <h2 class="text-text-main text-2xl font-bold tracking-tight">Modifier l'objectif</h2>
                <p class="text-text-muted text-sm">Ajuste ta cible, son échéance ou son intitulé.</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <Link
                    :href="route('goals.index')"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange min-h-touch inline-flex items-center gap-1 rounded-xl text-sm font-semibold focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">arrow_back</span>
                    Retour aux objectifs
                </Link>

                <GlassCard class="p-6">
                    <h3 class="text-text-main mb-6 text-lg font-bold">{{ goal.title }}</h3>

                    <GoalForm
                        :form="form"
                        :exercises="exercises"
                        :measurement-types="measurementTypes"
                        submit-label="Enregistrer les modifications"
                        @submit="submit"
                        @cancel="cancel"
                    />
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
