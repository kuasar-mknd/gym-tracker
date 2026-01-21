<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed, defineAsyncComponent } from 'vue'

const SupplementIntakeChart = defineAsyncComponent(() => import('@/Components/Stats/SupplementIntakeChart.vue'))

const props = defineProps({
    supplements: Array,
    intakeHistory: Array,
})

const showAddForm = ref(false)
const editingSupplement = ref(null)

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

const deleteSupplement = (id) => {
    if (confirm('Supprimer ce complément ?')) {
        router.delete(route('supplements.destroy', { supplement: id }))
    }
}

const consume = (id) => {
    router.post(
        route('supplements.consume', { supplement: id }),
        {},
        {
            preserveScroll: true,
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
                <GlassButton @click="showAddForm = true" variant="primary" class="hidden sm:flex">
                    <span class="material-symbols-outlined mr-2">add</span>
                    Ajouter
                </GlassButton>
                <!-- Mobile Add Button -->
                <button
                    @click="showAddForm = true"
                    class="bg-gradient-main flex size-12 items-center justify-center rounded-xl text-white shadow-lg active:scale-95 sm:hidden"
                >
                    <span class="material-symbols-outlined">add</span>
                </button>
            </div>

            <!-- Intake Chart -->
            <GlassCard v-if="intakeHistory && intakeHistory.some(d => d.count > 0)" class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-lg font-black uppercase italic text-text-main">
                        Historique de consommation
                    </h3>
                    <p class="text-xs font-semibold text-text-muted">30 derniers jours</p>
                </div>
                <SupplementIntakeChart :data="intakeHistory" />
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
                        <GlassInput v-model="form.brand" label="Marque (Optionnel)" placeholder="Ex: MyProtein" />
                        <GlassInput v-model="form.dosage" label="Dosage (Optionnel)" placeholder="Ex: 30g / scoop" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput v-model="form.servings_remaining" type="number" label="Doses restantes" />
                        <GlassInput v-model="form.low_stock_threshold" type="number" label="Alerte stock bas" />
                    </div>
                    <div class="flex gap-2">
                        <GlassButton type="submit" variant="primary" class="flex-1" :loading="form.processing">
                            Ajouter
                        </GlassButton>
                        <GlassButton type="button" variant="ghost" @click="showAddForm = false"> Annuler </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- List -->
            <div v-if="supplements.length === 0 && !showAddForm" class="animate-slide-up py-10 text-center">
                <div class="mb-4 text-6xl">💊</div>
                <p class="text-text-main text-lg font-bold">Aucun complément</p>
                <p class="text-text-muted mb-6 text-sm">
                    Ajoutez vos compléments pour suivre votre stock et consommation.
                </p>
                <GlassButton variant="primary" @click="showAddForm = true"> Commencer </GlassButton>
            </div>

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
                        <GlassInput v-model="editForm.name" placeholder="Nom" />
                        <GlassInput v-model="editForm.brand" placeholder="Marque" />
                        <div class="grid grid-cols-2 gap-2">
                            <GlassInput v-model="editForm.servings_remaining" type="number" label="Stock" />
                            <GlassInput v-model="editForm.low_stock_threshold" type="number" label="Seuil" />
                        </div>
                        <GlassInput v-model="editForm.dosage" placeholder="Dosage" />
                        <div class="mt-2 flex gap-2">
                            <GlassButton
                                @click="updateSupplement(supplement)"
                                variant="primary"
                                size="sm"
                                class="flex-1"
                                >Sauvegarder</GlassButton
                            >
                            <GlassButton @click="cancelEdit" variant="ghost" size="sm">Annuler</GlassButton>
                        </div>
                    </div>

                    <!-- View Mode -->
                    <div v-else class="flex h-full flex-col">
                        <div class="flex-1 p-4">
                            <div class="mb-2 flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex size-10 items-center justify-center rounded-lg bg-linear-to-br from-blue-400 to-cyan-300 text-white shadow-md"
                                    >
                                        <span class="material-symbols-outlined">medication</span>
                                    </div>
                                    <div>
                                        <h3 class="text-text-main leading-tight font-bold">{{ supplement.name }}</h3>
                                        <p class="text-text-muted text-xs font-bold tracking-wider uppercase">
                                            {{ supplement.brand || 'Générique' }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <button
                                        @click="startEdit(supplement)"
                                        class="text-text-muted hover:text-electric-orange p-1 transition-colors"
                                    >
                                        <span class="material-symbols-outlined text-lg">edit</span>
                                    </button>
                                    <button
                                        @click="deleteSupplement(supplement.id)"
                                        class="text-text-muted p-1 transition-colors hover:text-red-500"
                                    >
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 flex items-end justify-between">
                                <div>
                                    <p class="text-text-muted mb-1 text-xs font-semibold uppercase">Stock</p>
                                    <p
                                        class="font-display text-2xl font-black"
                                        :class="
                                            supplement.servings_remaining <= supplement.low_stock_threshold
                                                ? 'text-red-500'
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
                                class="text-text-muted mt-3 inline-flex items-center rounded-md border border-white/10 bg-white/5 px-2 py-1 text-xs font-medium"
                            >
                                {{ supplement.dosage }}
                            </div>
                        </div>

                        <!-- Action Footer -->
                        <div class="border-t border-white/5 bg-white/5 p-3">
                            <button
                                @click="consume(supplement.id)"
                                class="from-electric-orange to-vivid-violet flex w-full items-center justify-center gap-2 rounded-xl bg-linear-to-r py-2 text-sm font-bold text-white shadow-lg shadow-orange-500/20 transition-all hover:shadow-orange-500/40 active:scale-95"
                                :disabled="supplement.servings_remaining <= 0"
                                :class="{ 'cursor-not-allowed opacity-50': supplement.servings_remaining <= 0 }"
                            >
                                <span class="material-symbols-outlined text-lg">check_circle</span>
                                Prendre une dose
                            </button>
                        </div>
                    </div>
                </GlassCard>
            </div>
            <!-- List Padding for Mobile Bottom Nav -->
            <div class="h-24 sm:hidden"></div>
        </div>
    </AuthenticatedLayout>
</template>
