<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, Link, router } from '@inertiajs/vue3'

const props = defineProps({
    templates: {
        type: Array,
        default: () => [],
    },
})

const executeTemplate = (templateId) => {
    router.post(route('templates.execute', templateId))
}

const deleteTemplate = (templateId) => {
    if (confirm('Es-tu sûr de vouloir supprimer ce modèle ?')) {
        router.delete(route('templates.destroy', templateId))
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

        <div class="space-y-6">
            <div class="animate-slide-up">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-white">Mes Modèles</h2>
                    <Link :href="route('templates.create')">
                        <GlassButton variant="primary">
                            <svg
                                class="mr-2 h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 4v16m8-8H4"
                                />
                            </svg>
                            Nouveau Modèle
                        </GlassButton>
                    </Link>
                </div>
            </div>

            <div v-if="templates.length === 0" class="animate-slide-up" style="animation-delay: 0.1s">
                <GlassCard>
                    <div class="py-12 text-center">
                        <div class="mb-3 text-5xl">📋</div>
                        <h3 class="text-lg font-semibold text-white">Aucun modèle</h3>
                        <p class="mt-1 text-white/60">Crée tes routines pour gagner du temps</p>
                        <Link :href="route('templates.create')" class="mt-4 inline-block">
                            <GlassButton variant="primary">Créer mon premier modèle</GlassButton>
                        </Link>
                    </div>
                </GlassCard>
            </div>

            <div v-else class="grid animate-slide-up gap-4 md:grid-cols-2" style="animation-delay: 0.1s">
                <GlassCard v-for="template in templates" :key="template.id" class="flex flex-col">
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-white">{{ template.name }}</h3>
                                <p v-if="template.description" class="mt-1 text-sm text-white/60">
                                    {{ template.description }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="deleteTemplate(template.id)"
                                    class="rounded-lg p-2 text-white/40 transition hover:bg-red-500/20 hover:text-red-400"
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
                                class="flex items-center gap-2 rounded-lg bg-white/5 px-2 py-1 text-xs text-white/70"
                            >
                                <span class="font-medium">{{ line.exercise.name }}</span>
                                <span class="text-white/30">• {{ line.workout_template_sets.length }} séries</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <GlassButton variant="primary" class="w-full" @click="executeTemplate(template.id)">
                            Lancer cette séance
                        </GlassButton>
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
