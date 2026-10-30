<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { compte } from '@/Utils/nombre'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { useDemarrageDUneSeance } from '@/composables/useDemarrageDUneSeance'

defineProps({
    templates: {
        type: Array,
        default: () => [],
    },
    /**
     * De quoi lancer chaque modèle sans réseau, sous son identifiant : son
     * contenu et les clés que le serveur dérive (#2049). La page passe par
     * `useDemarrageDUneSeance`, qui le lit dans la page.
     */
    lancements: {
        type: Object,
        default: () => ({}),
    },
})

/*
 * Lancer un modèle passe par la file hors ligne, comme démarrer une séance
 * (#2049) : sans réseau, la séance, ses exercices et ses séries entrent en
 * file d'un seul coup et la séance s'ouvre. « Lancer » reste ouvert sur une
 * copie de la page : rien n'y part qui ne soit le geste de la personne
 * (`.ai/rules/js.md`). Supprimer un modèle, une visite, s'y désactive.
 */
const { demarrer, enCours: demarrageEnCours } = useDemarrageDUneSeance()
const { surUneCopie, raisonDUneEcriture } = useCopieHorsLigne()

// Tracks the template being launched: without it a double tap launches twice and
// creates two workouts, which the user then has to delete by hand.
const executingTemplateId = ref(null)

const executeTemplate = async (template) => {
    if (executingTemplateId.value !== null || demarrageEnCours.value) {
        return
    }

    executingTemplateId.value = template.id

    try {
        await demarrer(template)
    } finally {
        executingTemplateId.value = null
    }
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
        <!--
            « Nouveau modèle » est une navigation : `GlassButton` la rend en un
            seul lien par `href`, jamais un bouton posé dans un `Link` (#2117).
        -->
        <template #header-actions>
            <GlassButton :href="route('templates.create')" variant="primary" size="sm" aria-label="Nouveau modèle">
                <GlassIcon name="add" size="xs" />
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="titre-carte">Mes Modèles</h1>
                <GlassButton :href="route('templates.create')" variant="primary">
                    <GlassIcon name="add" size="xs" class="mr-2" />
                    Nouveau modèle
                </GlassButton>
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
                    <GlassButton :href="route('templates.create')" variant="primary"
                        >Créer mon premier modèle</GlassButton
                    >
                </template>
            </GlassEmptyState>

            <div v-else class="stagger-2 animate-slide-up grid gap-4 md:grid-cols-2">
                <GlassCard
                    v-for="template in templates"
                    :key="template.id"
                    class="group flex flex-col transition duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-[0.99]"
                >
                    <div class="flex-1">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="titre-carte">
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
                                    class="text-text-muted focus-visible:ring-accent-primary hover:text-accent-primary-deep hover:bg-surface-sunken flex size-11 items-center justify-center rounded-xl transition duration-300 hover:-translate-y-1 focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                                >
                                    <GlassIcon name="edit" size="sm" class="block" />
                                </Link>
                                <!-- title= is not a name on a touch device, where there
                                     is no hover to reveal it. -->
                                <GlassIconButton
                                    icon="delete"
                                    :label="`Supprimer ${template.name}`"
                                    ton="danger"
                                    :dusk="`delete-template-${template.id}`"
                                    :disabled="surUneCopie"
                                    :aria-describedby="raisonDUneEcriture"
                                    @click="demanderSuppression(template)"
                                />
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div
                                v-for="line in template.workout_template_lines"
                                :key="line.id"
                                class="text-text-muted border-border bg-surface-card/50 flex items-center gap-2 rounded-xl border px-2 py-1 text-xs transition duration-300 hover:-translate-y-0.5 active:scale-95"
                            >
                                <span class="text-text-main font-medium">{{ line.exercise.name }}</span>
                                <span class="text-text-muted/50"
                                    >• {{ compte(line.workout_template_sets_count || 0, 'série', 'séries') }}</span
                                >
                            </div>
                            <div
                                v-if="template.workout_template_lines_count > 3"
                                class="text-text-muted/50 mt-2 ml-1 text-xs font-bold italic"
                            >
                                + {{ compte(template.workout_template_lines_count - 3, 'exercice', 'exercices') }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <GlassButton
                            variant="primary"
                            class="w-full"
                            :disabled="executingTemplateId !== null || demarrageEnCours"
                            :loading="executingTemplateId === template.id"
                            :dusk="`execute-template-${template.id}`"
                            @click="executeTemplate(template)"
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
