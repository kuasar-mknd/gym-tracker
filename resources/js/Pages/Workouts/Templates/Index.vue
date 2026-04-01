<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, Link, router } from '@inertiajs/vue3'

const props = defineProps({
    templates: {
        type: Array,
        default: () => [],
    },
})

const executeTemplate = (templateId) => {
    router.post(route('templates.execute', { template: templateId }))
}

const deleteTemplate = (templateId) => {
    if (confirm('Es-tu sûr de vouloir supprimer ce modèle ?')) {
        router.delete(route('templates.destroy', { template: templateId }))
    }
}
</script>

<template>
    <Head title="Mes Modèles" />

    <AuthenticatedLayout page-title="Mes Modèles" show-back back-route="workouts.index">
        <template #header-actions>
            <Link :href="route('templates.create')">
                <GlassButton variant="primary" size="sm" aria-label="Nouveau modèle">
                    <svg
                        class="h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                </GlassButton>
            </Link>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mes Modèles</h2>
                <Link :href="route('templates.create')">
                    <GlassButton variant="primary">
                        <svg
                            class="mr-2 h-4 w-4"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Nouveau Modèle
                    </GlassButton>
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="templates.length === 0" class="animate-slide-up">
                <div
                    class="rounded-3xl border border-white/20 bg-white/10 p-8 backdrop-blur-md transition-all duration-300"
                >
                    <div class="py-12 text-center">
                        <div class="mb-3 text-5xl">📋</div>
                        <h3 class="text-text-main text-lg font-semibold dark:text-white">Aucun modèle</h3>
                        <p class="text-text-muted mt-1">Crée tes routines pour gagner du temps</p>
                        <Link :href="route('templates.create')" class="mt-4 inline-block">
                            <GlassButton variant="primary">Créer mon premier modèle</GlassButton>
                        </Link>
                    </div>
                </div>
            </div>

            <div v-else class="animate-slide-up grid gap-4 md:grid-cols-2" style="animation-delay: 0.1s">
                <div
                    v-for="template in templates"
                    :key="template.id"
                    class="group flex flex-col rounded-3xl border border-white/20 bg-white/10 p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
                >
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="font-display text-text-main text-xl font-black uppercase italic dark:text-white">{{ template.name }}</h3>
                                <p v-if="template.description" class="text-text-muted mt-1 text-sm font-medium">
                                    {{ template.description }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="deleteTemplate(template.id)"
                                    class="text-text-muted rounded-xl p-2 transition-all duration-300 hover:-translate-y-1 hover:bg-red-500/10 hover:text-red-500 active:scale-95"
                                    title="Supprimer"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                        />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div
                                v-for="line in template.workout_template_lines"
                                :key="line.id"
                                class="text-text-muted flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-2 py-1 text-xs backdrop-blur-sm transition-all duration-300 hover:-translate-y-0.5 hover:bg-white/10 active:scale-95"
                            >
                                <span class="text-text-main font-medium">{{ line.exercise.name }}</span>
                                <span class="text-text-muted/50"
                                    >• {{ line.workout_template_sets_count || 0 }} séries</span
                                >
                            </div>
                            <div
                                v-if="template.workout_template_lines_count > 3"
                                class="text-text-muted/50 mt-2 ml-1 text-xs font-bold italic"
                            >
                                + {{ template.workout_template_lines_count - 3 }} exercices
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <GlassButton variant="primary" class="w-full" @click="executeTemplate(template.id)">
                            Lancer cette séance
                        </GlassButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
