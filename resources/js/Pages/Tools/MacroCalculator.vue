<template>
    <Head title="Calculateur de Macros" />

    <AuthenticatedLayout show-back back-route="tools.index" largeur="etroite">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">Macros</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Estime tes besoins caloriques
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="stagger-1 animate-slide-up">
                <div class="space-y-6">
                    <!-- Gender Selection -->
                    <div>
                        <span id="macro-gender-label" class="font-display-label text-text-muted mb-2 block">Sexe</span>
                        <div class="grid grid-cols-2 gap-3" role="group" aria-labelledby="macro-gender-label">
                            <GlassTile label="Homme" :active="form.gender === 'male'" @click="form.gender = 'male'" />
                            <GlassTile
                                label="Femme"
                                ton="secondary"
                                :active="form.gender === 'female'"
                                @click="form.gender = 'female'"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <!-- Age -->
                        <GlassInput type="number" v-model="form.age" label="Age" placeholder="25" size="lg" />
                        <!-- Height -->
                        <GlassInput
                            type="number"
                            v-model="form.height"
                            label="Taille (cm)"
                            placeholder="175"
                            size="lg"
                        />
                        <!-- Weight -->
                        <GlassInput type="number" v-model="form.weight" label="Poids (kg)" placeholder="70" size="lg" />
                    </div>

                    <!-- Activity Level -->
                    <GlassSelect
                        v-model="form.activity_level"
                        label="Niveau d'activité"
                        :options="activityOptions"
                        size="lg"
                    />

                    <!-- Goal -->
                    <div>
                        <span id="macro-goal-label" class="font-display-label text-text-muted mb-2 block"
                            >Objectif</span
                        >
                        <div class="grid grid-cols-3 gap-3" role="group" aria-labelledby="macro-goal-label">
                            <button
                                v-for="goalOption in ['cut', 'maintain', 'bulk']"
                                :key="goalOption"
                                type="button"
                                @click="form.goal = goalOption"
                                :aria-pressed="form.goal === goalOption"
                                class="focus-visible:ring-accent-primary flex h-12 items-center justify-center rounded-xl border backdrop-blur-md transition focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    form.goal === goalOption
                                        ? 'border-accent-primary accent-fill shadow-glow-primary-soft'
                                        : 'text-text-muted hover:text-text-main border-border bg-surface-card/50 hover:border-border-strong hover:bg-surface-card/80'
                                "
                            >
                                <span class="font-display font-bold uppercase">{{
                                    goalOption === 'cut' ? 'Sèche' : goalOption === 'maintain' ? 'Maintien' : 'Prise'
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Result -->
                    <div
                        v-if="isValid"
                        class="border-border bg-surface-card/50 mt-6 flex flex-col items-center justify-center space-y-4 rounded-3xl border p-6 text-center"
                    >
                        <div>
                            <p class="text-text-muted text-sm font-bold tracking-wider uppercase">
                                Cibles Journalières
                            </p>
                            <div
                                class="from-accent-primary to-accent-secondary font-display mt-1 bg-linear-to-r bg-clip-text text-5xl font-black tracking-tighter text-transparent italic"
                            >
                                {{ entier(calculatedResults.targetCalories) }} kcal
                            </div>
                            <p class="text-text-muted text-xs font-semibold">
                                TDEE : {{ entier(calculatedResults.tdee) }} kcal
                            </p>
                        </div>

                        <div class="border-border grid w-full grid-cols-3 gap-4 border-t pt-4">
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Protéines</p>
                                <p class="font-display text-text-main text-2xl font-black">
                                    {{ entier(calculatedResults.protein) }} g
                                </p>
                            </div>
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Glucides</p>
                                <p class="font-display text-text-main text-2xl font-black">
                                    {{ entier(calculatedResults.carbs) }} g
                                </p>
                            </div>
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Lipides</p>
                                <p class="font-display text-text-main text-2xl font-black">
                                    {{ entier(calculatedResults.fat) }} g
                                </p>
                            </div>
                        </div>

                        <div class="mt-2 w-full pt-2">
                            <GlassButton
                                @click="saveCalculation"
                                variant="primary"
                                :loading="form.processing"
                                class="w-full"
                            >
                                Enregistrer
                            </GlassButton>

                            <p
                                v-if="Object.keys(form.errors).length"
                                class="text-accent-danger-deep mt-3 text-sm font-bold"
                                role="alert"
                                dusk="macro-error"
                            >
                                {{ Object.values(form.errors)[0] }}
                            </p>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- Trends Chart -->
            <GlassCard v-if="history.length > 1" class="stagger-2 animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Tendances</h3>
                    <p class="text-text-muted text-xs font-semibold">Évolution de vos objectifs</p>
                </div>
                <MacroHistoryChart :data="history" />
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="stagger-2 animate-slide-up">
                <div class="space-y-5">
                    <h2 class="font-display text-text-main text-lg font-black uppercase italic">Historique</h2>

                    <GlassEmptyState
                        v-if="history.length === 0"
                        taille="ligne"
                        icon="history"
                        title="Aucun historique"
                        description="Calcule tes macros pour garder une trace de tes objectifs."
                    />

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group border-border bg-surface-card/50 hover:bg-surface-card/80 relative flex flex-col justify-between rounded-2xl border p-4 transition hover:shadow-lg sm:flex-row sm:items-center"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="text-text-main bg-surface-sunken flex h-12 w-16 items-center justify-center rounded-xl text-lg font-bold"
                                >
                                    {{ entier(entry.target_calories) }}
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">
                                        {{ entry.protein }}P / {{ entry.carbs }}C / {{ entry.fat }}L
                                    </p>
                                    <p class="text-text-muted text-xs tracking-wider uppercase">
                                        {{
                                            entry.goal === 'cut'
                                                ? 'Sèche'
                                                : entry.goal === 'maintain'
                                                  ? 'Maintien'
                                                  : 'Prise'
                                        }}
                                        • {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <GlassIconButton
                                icon="delete"
                                label="Supprimer l'entrée"
                                ton="danger"
                                title="Supprimer l'entrée"
                                class="absolute top-4 right-4 sm:static"
                                @click="demanderSuppression(entry)"
                            />
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce calcul ?"
            :description="
                calculASupprimer
                    ? `Le calcul du ${new Date(calculASupprimer.created_at).toLocaleDateString('fr-FR')} sera effacé.`
                    : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { macroTargets } from '@/Utils/formulas'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import GlassTile from '@/Components/UI/GlassTile.vue'
import { entier } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const MacroHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/MacroHistoryChart.vue'))

defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const activityOptions = [
    { value: 'sedentary', label: "Sédentaire (peu ou pas d'exercice)" },
    { value: 'light', label: 'Légèrement actif (1-3 jours/semaine)' },
    { value: 'moderate', label: 'Modérément actif (3-5 jours/semaine)' },
    { value: 'very', label: 'Très actif (6-7 jours/semaine)' },
    { value: 'extra', label: 'Extrêmement actif (travail physique)' },
]

const form = useForm({
    gender: 'male',
    age: '',
    height: '',
    weight: '',
    activity_level: 'moderate',
    goal: 'maintain',
})

const isValid = computed(() => {
    return form.age > 0 && form.height > 0 && form.weight > 0
})

const calculatedResults = computed(() =>
    macroTargets({
        weight: form.weight,
        height: form.height,
        age: form.age,
        gender: form.gender,
        activityLevel: form.activity_level,
        goal: form.goal,
    }),
)

const saveCalculation = () => {
    if (!isValid.value) return

    form.post(route('tools.macro-calculator.store'), {
        preserveScroll: true,
        onSuccess: () => {
            // Optional: reset form or show success message
        },
        onError: () => triggerHaptic('error'),
    })
}

const {
    cible: calculASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((entry, termine) => {
    router.delete(route('tools.macro-calculator.destroy', { macroCalculation: entry.id }), {
        preserveScroll: true,
        onFinish: termine,
    })
})
</script>
