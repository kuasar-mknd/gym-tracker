<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import { dateAvecJour, parseCalendarDate, todayAsCalendarDate } from '@/Utils/date'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { nombre } from '@/Utils/nombre'
import { courbeDansLUniteDuDernierReleve } from '@/Utils/mesuresEnUnite'

// ⚡ PERFORMANCE OPTIMIZATION:
// Lazy-load the heavy chart component (which pulls in Chart.js) to reduce the initial JavaScript
// payload and speed up the page render time when the chart is not immediately interacted with.
const BodyPartHistoryChart = graphiqueAsynchrone(() => import('@/Components/Stats/BodyPartHistoryChart.vue'))

const props = defineProps({
    /** La clef de la partie, telle qu'elle est rangée et qu'on la renvoie. */
    part: String,
    /** Son nom à l'écran : « Taille » pour `Waist`, le nom saisi pour une autre. */
    label: { type: String, default: null },
    history: Array,
})

const nom = computed(() => props.label ?? props.part)

const courbe = computed(() => courbeDansLUniteDuDernierReleve(props.history))

const showAddForm = ref(false)
const formulaire = ref(null)

/**
 * Le formulaire s'ouvre au-dessus de l'historique : sous le pli depuis l'état vide, rien ne bougerait. Il se montre et
 * sa valeur prend le focus, quelle que soit la commande qui l'a ouvert (même geste que `Measurements/Index`).
 */
watch(showAddForm, async (ouvert) => {
    if (!ouvert) {
        return
    }

    await nextTick()
    formulaire.value?.querySelector('input')?.focus()
    formulaire.value?.scrollIntoView?.({ block: 'center' })
})

/**
 * La mesure rejoint l'historique de cette page : `keep_part_name` demande au
 * serveur de ne pas ramener à sa clef une « Taille » saisie à la main, même
 * quand le compte mesure aussi `Waist` (#1974).
 */
const form = useForm({
    part: props.part,
    keep_part_name: true,
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
const formatMeasuredAt = (measuredAt) => dateAvecJour(parseCalendarDate(measuredAt))
</script>

<template>
    <Head :title="nom" />

    <AuthenticatedLayout :page-title="nom" titre-plat>
        <!-- Une navigation : un seul lien, par `href` (#2117). -->
        <template #header-actions>
            <GlassButton :href="route('body-parts.index')" size="sm" variant="secondary" aria-label="Retour">
                <GlassIcon name="arrow_back" size="xs" />
            </GlassButton>
            <GlassButton
                :variant="showAddForm ? 'secondary' : 'primary'"
                size="sm"
                :aria-label="showAddForm ? 'Annuler la saisie' : 'Ajouter une mesure'"
                @click="showAddForm = !showAddForm"
            >
                <GlassIcon :name="showAddForm ? 'close' : 'add'" size="xs" />
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="titre-ecran">{{ nom }}</h1>
                <GlassButton :variant="showAddForm ? 'secondary' : 'primary'" @click="showAddForm = !showAddForm">
                    <GlassIcon :name="showAddForm ? 'close' : 'add'" size="xs" class="mr-2" />
                    {{ showAddForm ? 'Annuler' : 'Ajouter' }}
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Chart -->
            <GlassCard v-if="courbe.length > 0" class="animate-slide-up">
                <h3 class="titre-plat mb-4">Historique</h3>
                <BodyPartHistoryChart :data="courbe" :label="nom" :unit="courbe[courbe.length - 1].unit" />
            </GlassCard>

            <!-- Formulaire d'ajout -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="titre-plat mb-4">Nouvelle mesure</h3>
                <form ref="formulaire" @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.value"
                            type="number"
                            step="0.01"
                            label="Valeur"
                            placeholder="0,00"
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
            <div class="stagger-2 animate-slide-up space-y-2">
                <GlassCard v-for="item in [...history].reverse()" :key="item.id" padding="p-4" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-text-main text-xl font-bold"
                                    >{{ nombre(item.value, 2) }} {{ item.unit }}</span
                                >
                            </div>
                            <div class="text-text-muted text-sm font-medium">
                                {{ formatMeasuredAt(item.measured_at) }}
                            </div>
                            <div v-if="item.notes" class="text-text-muted mt-1 text-xs">
                                {{ item.notes }}
                            </div>
                        </div>
                        <!-- Icon-only and destructive: without a name a screen reader
                             announces nothing but "button". -->
                        <GlassIconButton
                            icon="delete"
                            :label="`Supprimer la mesure du ${formatMeasuredAt(item.measured_at)}`"
                            ton="danger"
                            class="souris:opacity-0 souris:group-hover:opacity-100 souris:focus-visible:opacity-100"
                            :dusk="`delete-measurement-${item.id}`"
                            @click="demanderSuppression(item)"
                        />
                    </div>
                </GlassCard>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cette mesure ?"
            :description="
                mesureASupprimer
                    ? `La mesure du ${formatMeasuredAt(mesureASupprimer.measured_at)} — ${nombre(mesureASupprimer.value, 2)} ${mesureASupprimer.unit} — sera effacée.`
                    : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
