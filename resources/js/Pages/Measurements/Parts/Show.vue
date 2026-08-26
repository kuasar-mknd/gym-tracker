<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { ref, defineAsyncComponent } from 'vue'
import { todayAsCalendarDate } from '@/Utils/date'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

// ⚡ PERFORMANCE OPTIMIZATION:
// Lazy-load the heavy chart component (which pulls in Chart.js) to reduce the initial JavaScript
// payload and speed up the page render time when the chart is not immediately interacted with.
const BodyPartHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/BodyPartHistoryChart.vue'))

const props = defineProps({
    part: String,
    history: Array,
})

const showAddForm = ref(false)

const form = useForm({
    part: props.part,
    value: '',
    unit: props.history.length > 0 ? props.history[props.history.length - 1].unit : 'cm',
    measured_at: todayAsCalendarDate(),
    notes: '',
})

const submit = () => {
    form.post(route('body-parts.store'), {
        onSuccess: () => {
            form.reset('value', 'notes')
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
    useForm({}).delete(route('body-parts.destroy', { bodyPartMeasurement: mesure.id }), { onFinish: termine })
})

/**
 * BodyMeasurementResource serialises measured_at as a bare 'Y-m-d', which
 * `new Date()` reads as UTC midnight — west of Greenwich that renders the
 * previous day. Pinning local midnight is the same guard Measurements/Index
 * already applies.
 */
const formatMeasuredAt = (measuredAt) =>
    new Date(`${measuredAt.substring(0, 10)}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })
</script>

<template>
    <Head :title="part" />

    <AuthenticatedLayout :page-title="part">
        <template #header-actions>
            <Link :href="route('body-parts.index')">
                <GlassButton size="sm" variant="secondary"> Retour </GlassButton>
            </Link>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">{{ part }}</h2>
                <GlassButton :variant="showAddForm ? 'secondary' : 'primary'" @click="showAddForm = !showAddForm">
                    <span class="material-symbols-outlined mr-2 text-base" aria-hidden="true">add</span>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Chart -->
            <GlassCard class="animate-slide-up">
                <h3 class="font-display text-accent-tertiary-deep mb-4 text-xs font-black tracking-[0.2em] uppercase">
                    History
                </h3>
                <BodyPartHistoryChart v-if="history.length > 0" :data="history" :label="part" :unit="history[0].unit" />
            </GlassCard>

            <!-- Formulaire d'ajout -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="text-text-main mb-4 font-semibold">Nouvelle mesure</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.value"
                            type="number"
                            step="0.1"
                            label="Valeur"
                            placeholder="0.00"
                            :error="form.errors.value"
                            inputmode="decimal"
                            required
                        />
                        <div class="space-y-1">
                            <label class="text-text-muted block text-sm font-medium">Unité</label>
                            <div
                                class="text-text-muted border-surface-card/10 bg-surface-card/5 rounded-xl border px-4 py-3"
                            >
                                {{ form.unit }}
                            </div>
                        </div>
                    </div>

                    <GlassInput
                        v-model="form.measured_at"
                        type="date"
                        label="Date"
                        :error="form.errors.measured_at"
                        required
                    />

                    <GlassInput v-model="form.notes" label="Notes (facultatif)" :error="form.errors.notes" />

                    <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                        Enregistrer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- List -->
            <div class="animate-slide-up space-y-2" style="animation-delay: 0.1s">
                <GlassCard v-for="item in [...history].reverse()" :key="item.id" padding="p-4" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-text-main text-xl font-bold">{{ item.value }} {{ item.unit }}</span>
                            </div>
                            <div class="text-text-muted text-sm font-medium">
                                {{ formatMeasuredAt(item.measured_at) }}
                            </div>
                            <div v-if="item.notes" class="text-text-muted/70 mt-1 text-xs italic">
                                {{ item.notes }}
                            </div>
                        </div>
                        <!-- Icon-only and destructive: without a name a screen reader
                             announces nothing but "button". -->
                        <button
                            type="button"
                            @click="demanderSuppression(item)"
                            :aria-label="`Supprimer la mesure du ${formatMeasuredAt(item.measured_at)}`"
                            :dusk="`delete-measurement-${item.id}`"
                            class="text-text-muted/30 hover:text-accent-danger-deep rounded-lg p-2 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100"
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
                </GlassCard>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cette mesure ?"
            :description="
                mesureASupprimer
                    ? `La mesure du ${formatMeasuredAt(mesureASupprimer.measured_at)} — ${mesureASupprimer.value} ${mesureASupprimer.unit} — sera effacée.`
                    : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
