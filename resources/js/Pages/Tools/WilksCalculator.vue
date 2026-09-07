<template>
    <Head title="Calculateur Wilks" />

    <AuthenticatedLayout show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">Wilks</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Compare ta force relative
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="stagger-1 animate-slide-up">
                <div class="space-y-6">
                    <!-- Unit Selection -->
                    <div class="flex justify-center">
                        <GlassSegmented
                            v-model="form.unit"
                            :options="[
                                { value: 'kg', label: 'KG' },
                                { value: 'lbs', label: 'LBS' },
                            ]"
                            label="Unité"
                            size="sm"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Gender Selection -->
                        <div>
                            <span id="wilks-gender-label" class="font-display-label text-text-muted mb-2 block"
                                >Sexe</span
                            >
                            <div class="grid grid-cols-2 gap-3" role="group" aria-labelledby="wilks-gender-label">
                                <GlassTile
                                    label="Homme"
                                    :active="form.gender === 'male'"
                                    @click="form.gender = 'male'"
                                />
                                <GlassTile
                                    label="Femme"
                                    ton="secondary"
                                    :active="form.gender === 'female'"
                                    @click="form.gender = 'female'"
                                />
                            </div>
                        </div>

                        <!-- Inputs -->
                        <div class="space-y-4">
                            <div>
                                <label for="wilks-body-weight" class="font-display-label text-text-muted mb-2 block"
                                    >Poids de corps</label
                                >
                                <div class="relative">
                                    <input
                                        id="wilks-body-weight"
                                        type="number"
                                        v-model="form.body_weight"
                                        placeholder="80"
                                        step="0.1"
                                        class="font-display text-text-main focus:border-accent-primary focus:ring-accent-primary/20 placeholder-text-muted/50 border-border bg-surface-card/50 focus:bg-surface-card/80 h-14 w-full rounded-2xl border px-4 text-xl font-bold transition outline-none focus:ring-2"
                                    />
                                    <span
                                        class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold uppercase"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                            <div>
                                <label for="wilks-lifted-weight" class="font-display-label text-text-muted mb-2 block"
                                    >Total soulevé</label
                                >
                                <div class="relative">
                                    <input
                                        id="wilks-lifted-weight"
                                        type="number"
                                        v-model="form.lifted_weight"
                                        placeholder="400"
                                        step="0.5"
                                        class="font-display text-text-main focus:border-accent-primary focus:ring-accent-primary/20 placeholder-text-muted/50 border-border bg-surface-card/50 focus:bg-surface-card/80 h-14 w-full rounded-2xl border px-4 text-xl font-bold transition outline-none focus:ring-2"
                                    />
                                    <span
                                        class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold uppercase"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result -->
                    <GlassCard class="mt-6 flex flex-col items-center justify-center p-8 text-center">
                        <p class="text-text-muted text-sm font-bold tracking-wider uppercase">Ton Score Wilks</p>
                        <div
                            class="from-accent-primary to-accent-secondary font-display mt-2 bg-linear-to-r bg-clip-text text-6xl font-black tracking-tighter text-transparent italic"
                        >
                            {{ scoreAffiche }}
                        </div>

                        <div class="mt-6">
                            <GlassButton
                                @click="saveScore"
                                variant="primary"
                                :disabled="!isValid"
                                :loading="form.processing"
                                class="min-w-[200px]"
                            >
                                Enregistrer
                            </GlassButton>

                            <p
                                v-if="Object.keys(form.errors).length"
                                class="text-accent-danger-deep mt-3 text-sm font-bold"
                                role="alert"
                                dusk="wilks-error"
                            >
                                {{ Object.values(form.errors)[0] }}
                            </p>
                        </div>
                    </GlassCard>
                </div>
            </GlassCard>

            <!-- Chart Section -->
            <GlassCard v-if="history.length > 0" class="stagger-2 animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Progression</h3>
                    <p class="text-text-muted text-xs font-semibold">Évolution du score</p>
                </div>
                <WilksHistoryChart :data="[...history].reverse()" />
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="stagger-2 animate-slide-up">
                <div class="space-y-5">
                    <h2 class="font-display text-text-main text-lg font-black uppercase italic">Historique</h2>

                    <div v-if="history.length > 0" class="mb-6">
                        <WilksScoreChart :data="history" />
                    </div>

                    <div v-if="history.length === 0" class="py-12 text-center">
                        <GlassIcon name="history" size="hero" class="text-surface-sunken mb-3" />
                        <p class="text-text-muted font-medium">Aucun historique.</p>
                        <p class="text-text-muted/70 mt-1 text-sm">
                            Calcule ton score pour commencer à suivre tes progrès.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group border-border bg-surface-card/50 hover:bg-surface-card/80 relative flex items-center justify-between rounded-2xl border p-4 transition hover:shadow-sm"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="text-text-main border-border bg-surface-card/80 flex h-12 w-12 items-center justify-center rounded-xl border text-xl font-bold"
                                >
                                    {{ nombre(entry.score, 0) }}
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">
                                        {{ nombre(entry.lifted_weight) }} {{ entry.unit }} /
                                        {{ nombre(entry.body_weight) }} {{ entry.unit }}
                                    </p>
                                    <p class="text-text-muted text-xs tracking-wider uppercase">
                                        {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <GlassIconButton
                                icon="delete"
                                label="Supprimer l'entrée"
                                ton="danger"
                                title="Supprimer l'entrée"
                                @click="demanderSuppression(entry)"
                            />
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce score ?"
            :description="
                scoreASupprimer
                    ? `Le score du ${new Date(scoreASupprimer.created_at).toLocaleDateString('fr-FR')} sera effacé.`
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
import { triggerHaptic } from '@/composables/useHaptics'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
// Aliased: this file also uses `wilksScore` as a route parameter name.
import { wilksScore as calculateWilks } from '@/Utils/formulas'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'
import GlassTile from '@/Components/UI/GlassTile.vue'
import { nombre } from '@/Utils/nombre'

const WilksScoreChart = defineAsyncComponent(() => import('@/Components/Stats/WilksScoreChart.vue'))
const WilksHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/WilksHistoryChart.vue'))

defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const form = useForm({
    body_weight: '',
    lifted_weight: '',
    gender: 'male',
    unit: 'kg',
})

const isValid = computed(() => {
    return form.body_weight > 0 && form.lifted_weight > 0
})

/**
 * The formula returns a number; the two decimals are this page's presentation
 * of it, not part of the calculation.
 */
const calculatedScore = computed(() =>
    calculateWilks({
        bodyWeight: form.body_weight,
        lifted: form.lifted_weight,
        gender: form.gender,
        unit: form.unit,
    }),
)

/** Deux décimales : la présentation de la page, pas le calcul. */
const scoreAffiche = computed(() => nombre(calculatedScore.value, 2, 2))

const saveScore = () => {
    if (!isValid.value) return

    form.post(route('tools.wilks.store'), {
        preserveScroll: true,
        onSuccess: () => {
            // Keep the form values so user can see what they just saved
        },
        // The isValid guard is looser than the server's rules, so a rejection is
        // reachable. Without this the button simply did nothing and said nothing.
        onError: () => triggerHaptic('error'),
    })
}

const {
    cible: scoreASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((entry, termine) => {
    router.delete(route('tools.wilks.destroy', { wilksScore: entry.id }), {
        preserveScroll: true,
        onFinish: termine,
    })
})
</script>
