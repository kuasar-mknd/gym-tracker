<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    supplements: Array,
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
    router.post(route('supplements.consume', { supplement: id }), {}, {
        preserveScroll: true,
    })
}

const formatDate = (dateString) => {
    if (!dateString) return 'Jamais'
    const date = new Date(dateString)
    const now = new Date()
    const diff = (now - date) / 1000 // seconds

    if (diff < 60) return 'À l\'instant'
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`

    return date.toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit'
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
                    <h1 class="font-display text-4xl font-black uppercase italic leading-none tracking-tighter text-text-main">
                        Mes <span class="text-gradient">Compléments</span>
                    </h1>
                    <p class="mt-2 text-sm font-semibold uppercase tracking-wider text-text-muted">
                        {{ supplements.length }} produits suivis
                    </p>
                </div>
                <GlassButton
                    @click="showAddForm = true"
                    variant="primary"
                    class="hidden sm:flex"
                >
                    <span class="material-symbols-outlined mr-2">add</span>
                    Ajouter
                </GlassButton>
                <!-- Mobile Add Button -->
                <button
                    @click="showAddForm = true"
                    class="flex size-12 items-center justify-center rounded-xl bg-gradient-main text-white shadow-lg active:scale-95 sm:hidden"
                >
                    <span class="material-symbols-outlined">add</span>
                </button>
            </div>

            <!-- Add Form -->
            <GlassCard v-if="showAddForm" class="animate-scale-in" variant="solid">
                <h3 class="mb-5 font-display text-xl font-black uppercase text-text-main">Nouveau Complément</h3>
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
                        />
                        <GlassInput
                            v-model="form.dosage"
                            label="Dosage (Optionnel)"
                            placeholder="Ex: 30g / scoop"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.servings_remaining"
                            type="number"
                            label="Doses restantes"
                        />
                        <GlassInput
                            v-model="form.low_stock_threshold"
                            type="number"
                            label="Alerte stock bas"
                        />
                    </div>
                    <div class="flex gap-2">
                         <GlassButton
                            type="submit"
                            variant="primary"
                            class="flex-1"
                            :loading="form.processing"
                        >
                            Ajouter
                        </GlassButton>
                         <GlassButton
                            type="button"
                            variant="ghost"
                            @click="showAddForm = false"
                        >
                            Annuler
                        </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- List -->
            <div v-if="supplements.length === 0 && !showAddForm" class="animate-slide-up text-center py-10">
                <div class="text-6xl mb-4">💊</div>
                <p class="font-bold text-text-main text-lg">Aucun complément</p>
                <p class="text-text-muted text-sm mb-6">Ajoutez vos compléments pour suivre votre stock et consommation.</p>
                <GlassButton variant="primary" @click="showAddForm = true">
                    Commencer
                </GlassButton>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 animate-slide-up">
                <GlassCard
                    v-for="supplement in supplements"
                    :key="supplement.id"
                    padding="p-0"
                    class="overflow-hidden flex flex-col"
                >
                    <!-- Edit Mode -->
                    <div v-if="editingSupplement === supplement.id" class="p-4 space-y-4">
                        <h3 class="font-bold text-text-main">Modifier</h3>
                        <GlassInput v-model="editForm.name" placeholder="Nom" />
                        <GlassInput v-model="editForm.brand" placeholder="Marque" />
                        <div class="grid grid-cols-2 gap-2">
                             <GlassInput v-model="editForm.servings_remaining" type="number" label="Stock" />
                             <GlassInput v-model="editForm.low_stock_threshold" type="number" label="Seuil" />
                        </div>
                        <GlassInput v-model="editForm.dosage" placeholder="Dosage" />
                        <div class="flex gap-2 mt-2">
                            <GlassButton @click="updateSupplement(supplement)" variant="primary" size="sm" class="flex-1">Sauvegarder</GlassButton>
                            <GlassButton @click="cancelEdit" variant="ghost" size="sm">Annuler</GlassButton>
                        </div>
                    </div>

                    <!-- View Mode -->
                    <div v-else class="flex flex-col h-full">
                        <div class="p-4 flex-1">
                            <div class="flex justify-between items-start mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="size-10 rounded-lg bg-gradient-to-br from-blue-400 to-cyan-300 flex items-center justify-center text-white shadow-md">
                                        <span class="material-symbols-outlined">medication</span>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-text-main leading-tight">{{ supplement.name }}</h3>
                                        <p class="text-xs text-text-muted font-bold uppercase tracking-wider">{{ supplement.brand || 'Générique' }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-1">
                                    <button @click="startEdit(supplement)" class="text-text-muted hover:text-electric-orange transition-colors p-1">
                                        <span class="material-symbols-outlined text-lg">edit</span>
                                    </button>
                                    <button @click="deleteSupplement(supplement.id)" class="text-text-muted hover:text-red-500 transition-colors p-1">
                                        <span class="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-end justify-between mt-4">
                                <div>
                                    <p class="text-xs text-text-muted font-semibold uppercase mb-1">Stock</p>
                                    <p
                                        class="text-2xl font-black font-display"
                                        :class="supplement.servings_remaining <= supplement.low_stock_threshold ? 'text-red-500' : 'text-text-main'"
                                    >
                                        {{ supplement.servings_remaining }}
                                        <span class="text-xs font-bold text-text-muted ml-0.5">doses</span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-text-muted font-semibold uppercase mb-1">Dernière prise</p>
                                    <p class="text-sm font-bold text-text-main">{{ formatDate(supplement.last_taken_at) }}</p>
                                </div>
                            </div>

                             <div v-if="supplement.dosage" class="mt-3 inline-flex items-center px-2 py-1 rounded-md bg-white/5 border border-white/10 text-xs font-medium text-text-muted">
                                {{ supplement.dosage }}
                            </div>
                        </div>

                        <!-- Action Footer -->
                        <div class="p-3 bg-white/5 border-t border-white/5">
                            <button
                                @click="consume(supplement.id)"
                                class="w-full flex items-center justify-center gap-2 py-2 rounded-xl bg-gradient-to-r from-electric-orange to-vivid-violet text-white font-bold text-sm shadow-lg shadow-orange-500/20 active:scale-95 transition-all hover:shadow-orange-500/40"
                                :disabled="supplement.servings_remaining <= 0"
                                :class="{'opacity-50 cursor-not-allowed': supplement.servings_remaining <= 0}"
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
