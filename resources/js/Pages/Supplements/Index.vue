<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, defineAsyncComponent } from 'vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

const SupplementUsageChart = defineAsyncComponent(() => import('@/Components/Stats/SupplementUsageChart.vue'))

defineProps({
    supplements: Array,
    usageHistory: Array,
})

const showAddForm = ref(false)
const editingSupplement = ref(null)
const consumingId = ref(null)

const form = useForm({
    name: '',
    brand: '',
    dosage: '',
    servings_remaining: 30,
    low_stock_threshold: 5,
})

const editForm = useForm({
    name: '',
    brand: '',
    dosage: '',
    servings_remaining: 0,
    low_stock_threshold: 0,
})

const submit = () => {
    form.post(route('supplements.store'), {
        onSuccess: () => {
            form.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (supplement) => {
    editingSupplement.value = supplement.id
    editForm.name = supplement.name
    editForm.brand = supplement.brand || ''
    editForm.dosage = supplement.dosage || ''
    editForm.servings_remaining = supplement.servings_remaining
    editForm.low_stock_threshold = supplement.low_stock_threshold
}

const cancelEdit = () => {
    editingSupplement.value = null
    editForm.reset()
}

const updateSupplement = (supplement) => {
    editForm.put(route('supplements.update', { supplement: supplement.id }), {
        onSuccess: () => {
            editingSupplement.value = null
        },
    })
}

const {
    cible: complementASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((complement, termine) => {
    router.delete(route('supplements.destroy', { supplement: complement.id }), { onFinish: termine })
})

const consume = (id) => {
    router.post(
        route('supplements.consume', { supplement: id }),
        {},
        {
            preserveScroll: true,
            onStart: () => (consumingId.value = id),
            onFinish: () => (consumingId.value = null),
        },
    )
}

const formatDate = (dateString) => {
    if (!dateString) return 'Jamais'
    const date = new Date(dateString)
    const now = new Date()
    const diff = (now - date) / 1000 // seconds

    if (diff < 60) return "À l'instant"
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`

    return date.toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <Head title="Compléments" />

    <AuthenticatedLayout liquid-variant="subtle">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                    >
                        Mes <span class="text-gradient">Compléments</span>
                    </h1>
                    <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                        {{ supplements.length }} produits suivis
                    </p>
                </div>
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    class="hidden size-11 shrink-0 items-center justify-center sm:flex"
                >
                    <span class="material-symbols-outlined mr-2" aria-hidden="true">add</span>
                    Ajouter
                </GlassButton>
                <!-- Mobile Add Button -->
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    aria-label="Ajouter un complément"
                    class="flex size-12 items-center justify-center p-0! sm:hidden"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                </GlassButton>
            </div>

            <!-- Usage Chart -->
            <GlassCard v-if="usageHistory && usageHistory.some((d) => d.count > 0)" class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                        Consommation Mensuelle
                    </h3>
                    <p class="text-text-muted text-xs font-semibold">30 derniers jours</p>
                </div>
                <SupplementUsageChart :data="usageHistory" />
            </GlassCard>

            <!-- Add Form -->
            <GlassCard v-if="showAddForm" class="animate-scale-in" variant="solid">
                <h3 class="font-display text-text-main mb-5 text-xl font-black uppercase">Nouveau Complément</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom"
                        placeholder="Ex: Whey Protein"
                        :error="form.errors.name"
                    />
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.brand"
                            label="Marque (Optionnel)"
                            placeholder="Ex: MyProtein"
                            :error="form.errors.brand"
                        />
                        <GlassInput
                            v-model="form.dosage"
                            label="Dosage (Optionnel)"
                            placeholder="Ex: 30g / scoop"
                            :error="form.errors.dosage"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.servings_remaining"
                            type="number"
                            label="Doses restantes"
                            :error="form.errors.servings_remaining"
                        />
                        <GlassInput
                            v-model="form.low_stock_threshold"
                            type="number"
                            label="Alerte stock bas"
                            :error="form.errors.low_stock_threshold"
                        />
                    </div>
                    <div class="flex gap-2">
                        <GlassButton type="submit" variant="primary" class="flex-1" :loading="form.processing">
                            Ajouter
                        </GlassButton>
                        <GlassButton type="button" variant="secondary" @click="showAddForm = false">
                            Annuler
                        </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- List -->
            <GlassEmptyState
                v-if="supplements.length === 0 && !showAddForm"
                class="animate-slide-up"
                icon="💊"
                color="pink"
                title="Aucun complément"
                description="Ajoutez vos compléments pour suivre votre stock et consommation."
                action-label="Commencer"
                action-id="empty-state-supplement"
                @action="showAddForm = true"
            />

            <div v-else class="animate-slide-up grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GlassCard
                    v-for="supplement in supplements"
                    :key="supplement.id"
                    padding="p-0"
                    class="flex flex-col overflow-hidden"
                >
                    <!-- Edit Mode -->
                    <div v-if="editingSupplement === supplement.id" class="space-y-4 p-4">
                        <h3 class="text-text-main font-bold">Modifier</h3>
                        <GlassInput v-model="editForm.name" placeholder="Nom" :error="editForm.errors.name" />
                        <GlassInput v-model="editForm.brand" placeholder="Marque" :error="editForm.errors.brand" />
                        <div class="grid grid-cols-2 gap-2">
                            <GlassInput
                                v-model="editForm.servings_remaining"
                                type="number"
                                label="Stock"
                                :error="editForm.errors.servings_remaining"
                            />
                            <GlassInput
                                v-model="editForm.low_stock_threshold"
                                type="number"
                                label="Seuil"
                                :error="editForm.errors.low_stock_threshold"
                            />
                        </div>
                        <GlassInput v-model="editForm.dosage" placeholder="Dosage" :error="editForm.errors.dosage" />
                        <div class="mt-2 flex gap-2">
                            <GlassButton
                                @click="updateSupplement(supplement)"
                                variant="primary"
                                size="sm"
                                class="flex-1"
                                >Enregistrer</GlassButton
                            >
                            <GlassButton @click="cancelEdit" variant="secondary" size="sm">Annuler</GlassButton>
                        </div>
                    </div>

                    <!-- View Mode -->
                    <div v-else class="flex h-full flex-col">
                        <div class="flex-1 p-4">
                            <div class="mb-2 flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="info-fill flex size-10 items-center justify-center rounded-lg shadow-md"
                                    >
                                        <span class="material-symbols-outlined" aria-hidden="true">medication</span>
                                    </div>
                                    <div>
                                        <h3 class="text-text-main leading-tight font-bold">{{ supplement.name }}</h3>
                                        <p class="text-text-muted text-xs font-bold tracking-wider uppercase">
                                            {{ supplement.brand || 'Générique' }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <GlassIconButton
                                        v-press
                                        icon="edit"
                                        label="Modifier le complément"
                                        ton="accent"
                                        @click="startEdit(supplement)"
                                    />
                                    <GlassIconButton
                                        v-press
                                        icon="delete"
                                        label="Supprimer le complément"
                                        ton="danger"
                                        @click="demanderSuppression(supplement)"
                                    />
                                </div>
                            </div>

                            <div class="mt-4 flex items-end justify-between">
                                <div>
                                    <p class="text-text-muted mb-1 text-xs font-semibold uppercase">Stock</p>
                                    <p
                                        class="font-display text-2xl font-black"
                                        :class="
                                            supplement.servings_remaining <= supplement.low_stock_threshold
                                                ? 'text-accent-danger-deep'
                                                : 'text-text-main'
                                        "
                                    >
                                        {{ supplement.servings_remaining }}
                                        <span class="text-text-muted ml-0.5 text-xs font-bold">doses</span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-text-muted mb-1 text-xs font-semibold uppercase">Dernière prise</p>
                                    <p class="text-text-main text-sm font-bold">
                                        {{ formatDate(supplement.last_taken_at) }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="supplement.dosage"
                                class="text-text-muted border-surface-card/10 bg-surface-card/5 mt-3 inline-flex items-center rounded-md border px-2 py-1 text-xs font-medium"
                            >
                                {{ supplement.dosage }}
                            </div>
                        </div>

                        <!-- Action Footer -->
                        <div class="border-surface-card/5 bg-surface-card/5 border-t p-3">
                            <GlassButton
                                @click="consume(supplement.id)"
                                variant="primary"
                                class="w-full"
                                size="sm"
                                :disabled="supplement.servings_remaining <= 0"
                                :loading="consumingId === supplement.id"
                                icon="check_circle"
                            >
                                Prendre une dose
                            </GlassButton>
                        </div>
                    </div>
                </GlassCard>
            </div>
            <!-- List Padding for Mobile Bottom Nav -->
            <div class="h-24 sm:hidden"></div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce complément ?"
            :description="complementASupprimer ? `« ${complementASupprimer.name} » sera retiré de ta liste.` : ''"
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
