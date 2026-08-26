<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

defineProps({
    templates: {
        type: Array,
        default: () => [],
    },
})

// Tracks the template being launched: without it a double tap posts twice and
// creates two workouts, which the user then has to delete by hand.
const executingTemplateId = ref(null)

const executeTemplate = (templateId) => {
    if (executingTemplateId.value !== null) {
        return
    }

    executingTemplateId.value = templateId

    router.post(
        route('templates.execute', { template: templateId }),
        {},
        {
            onFinish: () => {
                executingTemplateId.value = null
            },
        },
    )
}

const {
    cible: modeleASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((modele, termine) => {
    router.delete(route('templates.destroy', { template: modele.id }), { onFinish: termine })
})
</script>

<template>
    <Head title="Mes Modèles" />

    <AuthenticatedLayout page-title="Mes Modèles" show-back back-route="workouts.index">
        <template #header-actions>
            <Link :href="route('templates.create')">
                <GlassButton variant="primary" size="sm" aria-label="Nouveau modèle">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                </GlassButton>
            </Link>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mes Modèles</h2>
                <Link :href="route('templates.create')">
                    <GlassButton variant="primary">
                        <span class="material-symbols-outlined mr-2 text-base" aria-hidden="true">add</span>
                        Nouveau Modèle
                    </GlassButton>
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <GlassEmptyState
                v-if="templates.length === 0"
                class="animate-slide-up"
                icon="📋"
                color="violet"
                title="Aucun modèle"
                description="Crée tes routines pour gagner du temps."
            >
                <template #action>
                    <Link :href="route('templates.create')">
                        <GlassButton variant="primary">Créer mon premier modèle</GlassButton>
                    </Link>
                </template>
            </GlassEmptyState>

            <div v-else class="animate-slide-up grid gap-4 md:grid-cols-2" style="animation-delay: 0.1s">
                <GlassCard
                    v-for="template in templates"
                    :key="template.id"
                    class="group flex flex-col transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-[0.99]"
                >
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="font-display text-text-main text-xl font-black uppercase italic">
                                    {{ template.name }}
                                </h3>
                                <p v-if="template.description" class="text-text-muted mt-1 text-sm font-medium">
                                    {{ template.description }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <!-- The edit page existed and had no way in: nothing
                                     linked to templates.edit anywhere. -->
                                <Link
                                    :href="route('templates.edit', { template: template.id })"
                                    :dusk="`edit-template-${template.id}`"
                                    :aria-label="`Modifier ${template.name}`"
                                    class="text-text-muted focus-visible:ring-accent-primary hover:text-accent-primary-deep hover:bg-surface-sunken rounded-xl p-2 transition-all duration-300 hover:-translate-y-1 focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                                >
                                    <span class="material-symbols-outlined block text-xl" aria-hidden="true">edit</span>
                                </Link>
                                <!-- title= is not a name on a touch device, where there
                                     is no hover to reveal it. -->
                                <button
                                    type="button"
                                    @click="demanderSuppression(template)"
                                    :dusk="`delete-template-${template.id}`"
                                    :aria-label="`Supprimer ${template.name}`"
                                    class="text-text-muted focus-visible:ring-accent-primary hover:bg-accent-danger/10 hover:text-accent-danger-deep rounded-xl p-2 transition-all duration-300 hover:-translate-y-1 focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                                >
                                    <svg
                                        class="h-5 w-5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
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
                                class="text-text-muted border-border bg-surface-card/50 flex items-center gap-2 rounded-xl border px-2 py-1 text-xs transition-all duration-300 hover:-translate-y-0.5 active:scale-95"
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
                        <GlassButton
                            variant="primary"
                            class="w-full"
                            :disabled="executingTemplateId !== null"
                            :loading="executingTemplateId === template.id"
                            :dusk="`execute-template-${template.id}`"
                            @click="executeTemplate(template.id)"
                        >
                            {{ executingTemplateId === template.id ? 'Lancement…' : 'Lancer cette séance' }}
                        </GlassButton>
                    </div>
                </GlassCard>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce modèle ?"
            :description="modeleASupprimer ? `« ${modeleASupprimer.name} » sera définitivement effacé.` : ''"
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
