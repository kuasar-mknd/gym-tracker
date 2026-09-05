<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import { Head, useForm, Deferred, router } from '@inertiajs/vue3'
import { computed, ref, defineAsyncComponent } from 'vue'
import { parseCalendarDate, todayAsCalendarDate } from '@/Utils/date'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

const WeightHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/WeightHistoryChart.vue'))
const BodyFatLineChart = defineAsyncComponent(() => import('@/Components/Stats/BodyFatLineChart.vue'))

const props = defineProps({
    measurements: Array,
    // ⚡ Bolt: Consolidated deferred body stats
    bodyStats: {
        type: Object,
        default: () => ({
            weightHistory: [],
            bodyFatHistory: [],
        }),
    },
})

const showAddForm = ref(false)

const form = useForm({
    weight: '',
    body_fat: '',
    measured_at: todayAsCalendarDate(),
    notes: '',
})

const submit = () => {
    form.post(route('body-measurements.store'), {
        onSuccess: () => {
            form.reset('weight', 'body_fat', 'notes')
            showAddForm.value = false
        },
    })
}

const {
    cible: mesureASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((mesure, termine) => {
    router.delete(route('body-measurements.destroy', { body_measurement: mesure.id }), { onFinish: termine })
})

/**
 * Ce que `confirm()` ne pouvait pas dire : LAQUELLE.
 *
 * « Supprimer cette entrée ? » laisse l'utilisateur fermer la boîte pour aller
 * vérifier de quelle ligne il s'agissait.
 */
const descriptionSuppression = computed(() => {
    const mesure = mesureASupprimer.value

    if (mesure === null) {
        return ''
    }

    // `parseCalendarDate` et non `new Date` : `measured_at` est un JOUR, et
    // `new Date('2026-08-05')` se lit minuit UTC — donc la veille pour tout
    // fuseau derrière. Le dialogue aurait nommé le mauvais jour.
    const jour = parseCalendarDate(mesure.measured_at).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })

    return `La mesure du ${jour} sera définitivement effacée.`
})

const latestWeight = computed(() => {
    if (props.measurements.length === 0) return null
    return props.measurements[0].weight
})

const previousWeight = computed(() => {
    if (props.measurements.length < 2) return null
    return props.measurements[1].weight
})

const weightDiff = computed(() => {
    if (!latestWeight.value || !previousWeight.value) return null
    return (latestWeight.value - previousWeight.value).toFixed(1)
})

const latestBodyFat = computed(() => {
    // No empty-list guard: `find` already answers undefined on one, and the
    // ternary below turns that into null. The guard that used to sit here
    // changed nothing, which is why no mutation of it could ever fail a test.
    const latest = props.measurements.find((m) => m.body_fat !== null)

    return latest ? latest.body_fat : null
})
</script>

<template>
    <Head title="Mesures" />

    <AuthenticatedLayout page-title="Mesures">
        <template #header-actions>
            <!--
                Le variant suit l'ÉTAT : ce bouton bascule, et il dit
                « Annuler » une fois le formulaire ouvert. Un « Annuler » vert
                serait faux — c'est ce qui arrive quand on pose un variant sans
                regarder ce que le bouton fait dans son autre moitié.
            -->
            <GlassButton
                :variant="showAddForm ? 'secondary' : 'primary'"
                size="sm"
                :aria-label="showAddForm ? 'Annuler la saisie' : 'Ajouter une mesure'"
                @click="showAddForm = !showAddForm"
            >
                <span class="material-symbols-outlined text-sm" aria-hidden="true">
                    {{ showAddForm ? 'close' : 'add' }}
                </span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mesures</h2>
                <GlassButton :variant="showAddForm ? 'secondary' : 'primary'" @click="showAddForm = !showAddForm">
                    <span class="material-symbols-outlined mr-2 text-base" aria-hidden="true">
                        {{ showAddForm ? 'close' : 'add' }}
                    </span>
                    {{ showAddForm ? 'Annuler' : 'Ajouter' }}
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Quick Stats -->
            <div class="animate-slide-up grid grid-cols-1 gap-3 sm:grid-cols-3">
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-gradient text-3xl font-bold">
                            {{ latestWeight ? `${latestWeight}` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">kg actuel</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div
                            :class="[
                                'text-3xl font-bold',
                                weightDiff > 0
                                    ? 'text-trend-down'
                                    : weightDiff < 0
                                      ? 'text-trend-up'
                                      : 'text-text-muted',
                            ]"
                        >
                            {{ weightDiff ? `${weightDiff > 0 ? '+' : ''}${weightDiff}` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">kg évolution</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-accent-secondary-deep text-3xl font-bold">
                            {{ latestBodyFat ? `${latestBodyFat}%` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">Masse Grasse</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Add Form (collapsible) -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="text-text-main mb-4 font-semibold">Nouvelle entrée</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-3 gap-4">
                        <GlassInput
                            v-model="form.weight"
                            type="number"
                            step="0.1"
                            label="Poids (kg)"
                            placeholder="75.5"
                            :error="form.errors.weight"
                            inputmode="decimal"
                            required
                        />
                        <GlassInput
                            v-model="form.body_fat"
                            type="number"
                            step="0.1"
                            label="Gras (%)"
                            placeholder="15.0"
                            :error="form.errors.body_fat"
                            inputmode="decimal"
                        />
                        <GlassInput
                            v-model="form.measured_at"
                            type="date"
                            label="Date"
                            :error="form.errors.measured_at"
                            required
                        />
                    </div>
                    <GlassInput
                        v-model="form.notes"
                        label="Notes (optionnel)"
                        placeholder="Matin, à jeun..."
                        :error="form.errors.notes"
                    />
                    <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                        Enregistrer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- Charts -->
            <Deferred data="bodyStats">
                <template #fallback>
                    <div class="animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2" style="animation-delay: 0.1s">
                        <GlassCard v-for="i in 2" :key="i">
                            <GlassSkeleton width="120px" height="1rem" class="mb-4" />
                            <div class="h-64">
                                <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                            </div>
                        </GlassCard>
                    </div>
                </template>

                <div class="animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2" style="animation-delay: 0.1s">
                    <!-- Weight Chart -->
                    <GlassCard>
                        <h3
                            class="font-display text-accent-info-deep mb-4 text-xs font-black tracking-[0.2em] uppercase"
                        >
                            Évolution Poids
                        </h3>
                        <div class="h-64">
                            <WeightHistoryChart
                                hauteur="h-full"
                                v-if="bodyStats?.weightHistory && bodyStats.weightHistory.length > 0"
                                :data="bodyStats.weightHistory"
                            />
                            <div v-else class="text-text-muted/50 flex h-full items-center justify-center font-medium">
                                Aucune donnée disponible
                            </div>
                        </div>
                    </GlassCard>

                    <!-- Body Fat Chart -->
                    <GlassCard>
                        <h3
                            class="font-display text-accent-secondary-deep mb-4 text-xs font-black tracking-[0.2em] uppercase"
                        >
                            Évolution Masse Grasse
                        </h3>
                        <div class="h-64">
                            <BodyFatLineChart
                                hauteur="h-full"
                                v-if="bodyStats?.bodyFatHistory && bodyStats.bodyFatHistory.length > 0"
                                :data="bodyStats.bodyFatHistory"
                            />
                            <div v-else class="text-text-muted/50 flex h-full items-center justify-center font-medium">
                                Aucune donnée disponible
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </Deferred>

            <!-- History -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="font-display text-accent-info-deep mb-3 text-xs font-black tracking-[0.2em] uppercase">
                    Historique
                </h3>

                <div v-if="measurements.length === 0">
                    <GlassCard>
                        <div class="py-8 text-center">
                            <div class="mb-2 text-4xl">⚖️</div>
                            <p class="text-text-muted">Aucune mesure pour l'instant</p>
                        </div>
                    </GlassCard>
                </div>

                <div v-else class="space-y-2">
                    <GlassCard v-for="measurement in measurements" :key="measurement.id" padding="p-4" class="group">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-text-main text-xl font-bold">{{ measurement.weight }} kg</span>
                                    <span
                                        v-if="measurement.body_fat"
                                        class="bg-accent-secondary/15 text-accent-secondary-deep rounded-full px-2 py-0.5 text-xs font-bold"
                                        >{{ measurement.body_fat }}% BF</span
                                    >
                                </div>
                                <div class="text-text-muted text-sm font-medium">
                                    {{
                                        parseCalendarDate(measurement.measured_at)?.toLocaleDateString('fr-FR', {
                                            weekday: 'short',
                                            day: 'numeric',
                                            month: 'short',
                                            year: 'numeric',
                                        })
                                    }}
                                </div>
                                <div v-if="measurement.notes" class="text-text-muted/70 mt-1 text-xs italic">
                                    {{ measurement.notes }}
                                </div>
                            </div>
                            <GlassIconButton
                                icon="delete"
                                :label="`Supprimer la mesure du ${measurement.measured_at.substring(0, 10)}`"
                                ton="danger"
                                class="opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100"
                                @click="demanderSuppression(measurement)"
                            />
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cette mesure ?"
            :description="descriptionSuppression"
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
