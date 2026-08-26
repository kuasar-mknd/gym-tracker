<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { ref, defineAsyncComponent } from 'vue'
import { parseCalendarDate, todayAsCalendarDate } from '@/Utils/date'

const BodyPartDiffChart = defineAsyncComponent(() => import('@/Components/Stats/BodyPartDiffChart.vue'))

defineProps({
    latestMeasurements: Array,
    commonParts: Array,
})

const showAddForm = ref(false)

const form = useForm({
    part: '',
    value: '',
    unit: 'cm',
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

const selectCommonPart = (part) => {
    form.part = part
}
</script>

<template>
    <Head title="Mesures" />

    <AuthenticatedLayout page-title="Mesures">
        <template #header-actions>
            <GlassButton
                :variant="showAddForm ? 'secondary' : 'primary'"
                size="sm"
                :aria-label="showAddForm ? 'Annuler la saisie' : 'Ajouter une mesure'"
                @click="showAddForm = !showAddForm"
            >
                <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mesures</h2>
                <GlassButton :variant="showAddForm ? 'secondary' : 'primary'" @click="showAddForm = !showAddForm">
                    <span class="material-symbols-outlined mr-2 text-base" aria-hidden="true">add</span>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Formulaire d'ajout -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="text-text-main mb-4 font-semibold">Nouvelle mesure</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="text-text-muted mb-1 block text-sm font-medium">Partie du corps</label>
                        <div class="mb-2 flex flex-wrap gap-2">
                            <button
                                v-for="part in commonParts"
                                :key="part"
                                type="button"
                                @click="selectCommonPart(part)"
                                :class="[
                                    'rounded-full px-3 py-1 text-xs transition',
                                    form.part === part
                                        ? 'accent-fill'
                                        : 'text-text-muted bg-surface-sunken hover:bg-surface-sunken',
                                ]"
                            >
                                {{ part }}
                            </button>
                        </div>
                        <GlassInput v-model="form.part" placeholder="Ex: Waist" :error="form.errors.part" required />
                    </div>

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
                            <GlassSelect
                                v-model="form.unit"
                                label="Unité"
                                :options="[
                                    { value: 'cm', label: 'cm' },
                                    { value: 'in', label: 'in' },
                                ]"
                            />
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

            <!-- Chart -->
            <GlassCard v-if="latestMeasurements.some((m) => m.diff !== 0)" class="animate-slide-up">
                <h3 class="font-display text-accent-state-deep mb-4 text-xs font-black tracking-[0.2em] uppercase">
                    Évolution Récente
                </h3>
                <BodyPartDiffChart :data="latestMeasurements" />
            </GlassCard>

            <!-- Grid -->
            <div
                class="animate-slide-up grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                style="animation-delay: 0.1s"
            >
                <Link
                    v-press
                    v-for="item in latestMeasurements"
                    :key="item.part"
                    :href="route('body-parts.show', { part: item.part })"
                    class="block"
                >
                    <GlassCard
                        class="h-full overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-[0.99]"
                    >
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-text-main font-bold">{{ item.part }}</h3>
                                <div
                                    class="from-accent-tertiary to-accent-secondary mt-1 bg-gradient-to-r bg-clip-text text-2xl font-bold text-transparent"
                                >
                                    {{ item.current }} <span class="text-text-muted text-sm">{{ item.unit }}</span>
                                </div>
                                <div class="text-text-muted mt-1 text-xs">
                                    {{ parseCalendarDate(item.date)?.toLocaleDateString() }}
                                </div>
                            </div>
                            <div
                                v-if="item.diff !== 0"
                                :class="[
                                    'flex items-center text-sm font-bold',
                                    item.diff > 0 ? 'text-trend-up' : 'text-trend-down',
                                ]"
                            >
                                {{ item.diff > 0 ? '+' : '' }}{{ item.diff }}
                            </div>
                        </div>
                    </GlassCard>
                </Link>

                <!-- Empty State -->
                <div v-if="latestMeasurements.length === 0 && !showAddForm" class="col-span-full py-12 text-center">
                    <p class="text-text-muted">Aucune mesure enregistrée.</p>
                    <GlassButton variant="primary" class="mt-4" @click="showAddForm = true"> Commencer </GlassButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
