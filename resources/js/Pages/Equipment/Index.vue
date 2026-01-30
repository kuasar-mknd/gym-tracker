<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import Modal from '@/Components/Modal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    equipment: Array,
})

const showModal = ref(false)
const editingEquipment = ref(null)

const form = useForm({
    name: '',
    type: 'shoes',
    brand: '',
    model: '',
    purchased_at: '',
    is_active: true,
    notes: '',
})

const equipmentTypes = [
    { value: 'shoes', label: 'Chaussures' },
    { value: 'belt', label: 'Ceinture' },
    { value: 'sleeves', label: 'Genouillères/Coudières' },
    { value: 'wraps', label: 'Bandes (Poignets/Genoux)' },
    { value: 'straps', label: 'Sangles' },
    { value: 'other', label: 'Autre' },
]

const openCreateModal = () => {
    editingEquipment.value = null
    form.reset()
    form.is_active = true
    showModal.value = true
}

const openEditModal = (item) => {
    editingEquipment.value = item
    form.name = item.name
    form.type = item.type
    form.brand = item.brand || ''
    form.model = item.model || ''
    form.purchased_at = item.purchased_at || ''
    form.is_active = !!item.is_active
    form.notes = item.notes || ''
    showModal.value = true
}

const submit = () => {
    if (editingEquipment.value) {
        form.put(route('equipment.update', editingEquipment.value.id), {
            onSuccess: () => closeModal(),
        })
    } else {
        form.post(route('equipment.store'), {
            onSuccess: () => closeModal(),
        })
    }
}

const closeModal = () => {
    showModal.value = false
    form.reset()
    editingEquipment.value = null
}

const deleteEquipment = (item) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cet équipement ?')) {
        router.delete(route('equipment.destroy', item.id))
    }
}

const activeEquipment = computed(() => props.equipment.filter((e) => e.is_active))
const retiredEquipment = computed(() => props.equipment.filter((e) => !e.is_active))

const getTypeLabel = (value) => {
    return equipmentTypes.find((t) => t.value === value)?.label || value
}

const formatDate = (dateString) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })
}

const getTypeIcon = (type) => {
    switch (type) {
        case 'shoes': return 'hiking'; // or footsteps
        case 'belt': return 'fitness_center';
        case 'sleeves': return 'accessibility_new';
        case 'wraps': return 'medical_services';
        case 'straps': return 'link';
        default: return 'inventory_2';
    }
}
</script>

<template>
    <Head title="Équipement" />

    <AuthenticatedLayout liquid-variant="subtle">
        <template #header-actions>
             <GlassButton @click="openCreateModal" variant="primary" size="sm" class="hidden sm:flex">
                <span class="material-symbols-outlined mr-2">add</span>
                Ajouter
            </GlassButton>
        </template>

        <div class="space-y-8">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                    >
                        Mon <span class="text-gradient">Équipement</span>
                    </h1>
                    <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                        Gère ton matériel d'entraînement
                    </p>
                </div>
                <!-- Mobile Add Button -->
                <button
                    @click="openCreateModal"
                    class="bg-gradient-main flex size-12 items-center justify-center rounded-xl text-white shadow-lg active:scale-95 sm:hidden"
                >
                    <span class="material-symbols-outlined">add</span>
                </button>
            </div>

            <!-- Active Equipment -->
            <section class="space-y-4">
                <h2 class="font-display text-text-main text-xl font-black uppercase italic flex items-center gap-2">
                    <span class="material-symbols-outlined text-electric-orange">check_circle</span>
                    Actifs
                </h2>

                <div v-if="activeEquipment.length === 0" class="py-8 text-center bg-white/5 rounded-3xl border border-white/10 backdrop-blur-sm">
                    <p class="text-text-muted font-medium">Aucun équipement actif.</p>
                    <GlassButton @click="openCreateModal" variant="ghost" size="sm" class="mt-4">Ajouter</GlassButton>
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <GlassCard
                        v-for="item in activeEquipment"
                        :key="item.id"
                        class="group relative flex flex-col justify-between overflow-hidden transition-all hover:bg-white/10"
                        padding="p-5"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 text-white shadow-lg">
                                    <span class="material-symbols-outlined">{{ getTypeIcon(item.type) }}</span>
                                </div>
                                <div>
                                    <h3 class="text-text-main font-bold leading-tight">{{ item.name }}</h3>
                                    <p class="text-text-muted text-xs font-bold uppercase tracking-wider">{{ item.brand }} {{ item.model }}</p>
                                </div>
                            </div>
                            <div class="flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <button @click="openEditModal(item)" class="text-text-muted hover:text-electric-orange p-1 transition-colors">
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                </button>
                                <button @click="deleteEquipment(item)" class="text-text-muted hover:text-red-500 p-1 transition-colors">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-text-muted font-semibold uppercase">Type</span>
                                <span class="text-text-main font-bold">{{ getTypeLabel(item.type) }}</span>
                            </div>
                             <div class="flex items-center justify-between text-xs">
                                <span class="text-text-muted font-semibold uppercase">Acheté le</span>
                                <span class="text-text-main font-bold">{{ formatDate(item.purchased_at) }}</span>
                            </div>
                        </div>

                         <div v-if="item.notes" class="mt-4 border-t border-white/10 pt-3 text-xs text-text-muted italic">
                            "{{ item.notes }}"
                        </div>
                    </GlassCard>
                </div>
            </section>

            <!-- Retired Equipment -->
            <section v-if="retiredEquipment.length > 0" class="space-y-4 pt-4 border-t border-white/10">
                <h2 class="font-display text-text-muted text-lg font-black uppercase italic flex items-center gap-2 opacity-70">
                    <span class="material-symbols-outlined">archive</span>
                    Retirés
                </h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 opacity-60 hover:opacity-100 transition-opacity">
                     <GlassCard
                        v-for="item in retiredEquipment"
                        :key="item.id"
                        class="group relative flex flex-col justify-between overflow-hidden bg-slate-100/5"
                        padding="p-5"
                    >
                         <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3 grayscale">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-200 text-slate-500 shadow-sm">
                                    <span class="material-symbols-outlined text-lg">{{ getTypeIcon(item.type) }}</span>
                                </div>
                                <div>
                                    <h3 class="text-text-main font-bold leading-tight">{{ item.name }}</h3>
                                    <p class="text-text-muted text-xs font-bold uppercase tracking-wider">{{ getTypeLabel(item.type) }}</p>
                                </div>
                            </div>
                             <div class="flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <button @click="openEditModal(item)" class="text-text-muted hover:text-electric-orange p-1 transition-colors">
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                </button>
                                <button @click="deleteEquipment(item)" class="text-text-muted hover:text-red-500 p-1 transition-colors">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </section>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <h2 class="font-display text-text-main mb-6 text-xl font-black uppercase italic">
                    {{ editingEquipment ? 'Modifier Équipement' : 'Ajouter Équipement' }}
                </h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <InputLabel value="Nom de l'équipement" />
                        <GlassInput v-model="form.name" placeholder="Ex: Nike Romaleos 4" :error="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Type" />
                            <select
                                v-model="form.type"
                                class="w-full rounded-2xl border-2 border-white/20 bg-white/50 px-4 py-3 font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-4 focus:ring-electric-orange/10"
                            >
                                <option v-for="type in equipmentTypes" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                            <div v-if="form.errors.type" class="mt-1 text-xs text-red-500">{{ form.errors.type }}</div>
                        </div>
                         <div>
                            <InputLabel value="Date d'achat" />
                            <GlassInput type="date" v-model="form.purchased_at" :error="form.errors.purchased_at" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Marque (Optionnel)" />
                            <GlassInput v-model="form.brand" placeholder="Ex: Nike" :error="form.errors.brand" />
                        </div>
                        <div>
                            <InputLabel value="Modèle (Optionnel)" />
                            <GlassInput v-model="form.model" placeholder="Ex: Romaleos 4" :error="form.errors.model" />
                        </div>
                    </div>

                    <div>
                        <InputLabel value="Notes (Optionnel)" />
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            class="w-full rounded-2xl border-2 border-white/20 bg-white/50 px-4 py-3 font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-4 focus:ring-electric-orange/10"
                            placeholder="Notes..."
                        ></textarea>
                        <div v-if="form.errors.notes" class="mt-1 text-xs text-red-500">{{ form.errors.notes }}</div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            id="is_active"
                            v-model="form.is_active"
                            class="rounded border-gray-300 text-electric-orange shadow-sm focus:ring-electric-orange"
                        />
                        <label for="is_active" class="text-text-main font-bold text-sm select-none">Actif</label>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <GlassButton @click="closeModal" variant="ghost" type="button">Annuler</GlassButton>
                        <GlassButton type="submit" variant="primary" :loading="form.processing">
                            {{ editingEquipment ? 'Sauvegarder' : 'Créer' }}
                        </GlassButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
