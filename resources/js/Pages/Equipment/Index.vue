<template>
    <Head title="Vestiaire" />

    <AuthenticatedLayout page-title="Vestiaire" show-back back-route="dashboard">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in flex items-center justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                    >
                        Mon<br />
                        <span class="text-gradient">Vestiaire</span>
                    </h1>
                    <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                        Gère ton équipement
                    </p>
                </div>
                <GlassButton @click="openModal()" variant="primary" icon="add">
                    Ajouter
                </GlassButton>
            </header>

            <!-- Equipment Grid -->
            <div v-if="equipment.length === 0" class="glass-panel-light animate-slide-up rounded-3xl p-12 text-center">
                <span class="material-symbols-outlined mb-4 text-6xl text-slate-300">checkroom</span>
                <p class="text-text-main text-lg font-bold">Ton vestiaire est vide</p>
                <p class="text-text-muted mt-1 text-sm">Ajoute tes chaussures, ceintures et accessoires.</p>
                <div class="mt-6">
                    <GlassButton @click="openModal()" variant="primary">
                        Ajouter un équipement
                    </GlassButton>
                </div>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GlassCard
                    v-for="(item, index) in equipment"
                    :key="item.id"
                    class="animate-slide-up group relative overflow-hidden"
                    :style="{ animationDelay: `${index * 0.05}s` }"
                    :padding="'p-0'"
                >
                    <div class="p-5">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-12 items-center justify-center rounded-xl bg-slate-50 text-slate-600 shadow-inner"
                                    :class="{
                                        'bg-blue-50 text-blue-600': item.type === 'shoes',
                                        'bg-orange-50 text-orange-600': item.type === 'belt',
                                        'bg-purple-50 text-purple-600': item.type === 'sleeves',
                                    }"
                                >
                                    <span class="material-symbols-outlined text-2xl">{{ getIcon(item.type) }}</span>
                                </div>
                                <div>
                                    <h3 class="font-display text-text-main text-lg font-black uppercase italic leading-none">
                                        {{ item.name }}
                                    </h3>
                                    <p class="text-text-muted text-xs font-bold uppercase">{{ item.brand }} {{ item.model }}</p>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <span
                                    class="glass-badge"
                                    :class="item.is_active ? 'glass-badge-success' : 'glass-badge-neutral'"
                                >
                                    {{ item.is_active ? 'Actif' : 'Retiré' }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <div v-if="item.purchased_at" class="flex items-center gap-2 text-xs text-slate-500">
                                <span class="material-symbols-outlined text-base">calendar_today</span>
                                <span>Acheté le {{ formatDate(item.purchased_at) }}</span>
                            </div>
                            <div v-if="item.notes" class="flex items-start gap-2 text-xs text-slate-500">
                                <span class="material-symbols-outlined text-base">notes</span>
                                <span class="line-clamp-2">{{ item.notes }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions Overlay -->
                     <div
                        class="absolute inset-0 z-10 flex items-center justify-center gap-2 bg-white/60 backdrop-blur-sm opacity-0 transition-opacity duration-200 group-hover:opacity-100"
                    >
                        <button
                            @click="openModal(item)"
                            class="flex size-10 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg hover:text-blue-600 hover:scale-110 transition-all"
                        >
                            <span class="material-symbols-outlined">edit</span>
                        </button>
                        <button
                            @click="deleteItem(item)"
                            class="flex size-10 items-center justify-center rounded-full bg-white text-slate-700 shadow-lg hover:text-red-600 hover:scale-110 transition-all"
                        >
                            <span class="material-symbols-outlined">delete</span>
                        </button>
                    </div>
                </GlassCard>
            </div>
        </div>

        <!-- Form Modal -->
        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <h2 class="font-display text-text-main mb-6 text-2xl font-black uppercase italic">
                    {{ form.id ? 'Modifier' : 'Ajouter' }} un équipement
                </h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Name -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Nom</label>
                        <GlassInput v-model="form.name" placeholder="ex: Romaleos 4" required />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>

                    <!-- Type -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Type</label>
                        <div class="relative">
                            <select
                                v-model="form.type"
                                class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-12 w-full appearance-none rounded-xl border-2 border-slate-200 bg-white px-4 pr-10 text-sm font-bold transition-all outline-none focus:ring-2"
                            >
                                <option value="shoes">Chaussures</option>
                                <option value="belt">Ceinture</option>
                                <option value="sleeves">Genouillères/Coudières</option>
                                <option value="straps">Sangles</option>
                                <option value="other">Autre</option>
                            </select>
                            <span class="material-symbols-outlined pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-slate-400">expand_more</span>
                        </div>
                        <InputError :message="form.errors.type" class="mt-1" />
                    </div>

                    <!-- Brand & Model -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Marque</label>
                            <GlassInput v-model="form.brand" placeholder="ex: Nike" />
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Modèle</label>
                            <GlassInput v-model="form.model" placeholder="ex: Romaleos" />
                        </div>
                    </div>

                    <!-- Purchased At -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Date d'achat</label>
                        <GlassInput type="date" v-model="form.purchased_at" />
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Notes</label>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 w-full rounded-xl border-2 border-slate-200 bg-white p-4 text-sm font-bold transition-all outline-none focus:ring-2"
                            placeholder="Notes, taille, prix..."
                        ></textarea>
                    </div>

                    <!-- Active Status -->
                    <div class="flex items-center gap-3">
                         <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" v-model="form.is_active" class="peer sr-only">
                            <div class="peer h-6 w-11 rounded-full bg-slate-200 after:absolute after:top-[2px] after:left-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all after:content-[''] peer-checked:bg-blue-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 dark:border-gray-600 dark:bg-gray-700 dark:peer-focus:ring-blue-800"></div>
                            <span class="font-display-label text-text-muted ml-3 text-sm">Équipement actif</span>
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end gap-3 border-t border-slate-100 pt-4">
                        <GlassButton @click="closeModal" type="button" variant="ghost">Annuler</GlassButton>
                        <GlassButton type="submit" variant="primary" :disabled="form.processing">
                            {{ form.id ? 'Mettre à jour' : 'Ajouter' }}
                        </GlassButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
    equipment: Array,
})

const showModal = ref(false)
const form = useForm({
    id: null,
    name: '',
    type: 'shoes',
    brand: '',
    model: '',
    purchased_at: '',
    is_active: true,
    notes: '',
})

const openModal = (item = null) => {
    if (item) {
        form.id = item.id
        form.name = item.name
        form.type = item.type
        form.brand = item.brand
        form.model = item.model
        form.purchased_at = item.purchased_at
        form.is_active = Boolean(item.is_active)
        form.notes = item.notes
    } else {
        form.reset()
        form.id = null
        form.is_active = true
    }
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
}

const submit = () => {
    if (form.id) {
        form.patch(route('equipment.update', form.id), {
            onSuccess: () => closeModal(),
        })
    } else {
        form.post(route('equipment.store'), {
            onSuccess: () => closeModal(),
        })
    }
}

const deleteItem = (item) => {
    if (confirm('Voulez-vous vraiment supprimer cet équipement ?')) {
        router.delete(route('equipment.destroy', item.id))
    }
}

const getIcon = (type) => {
    const icons = {
        shoes: 'steps',
        belt: 'fitness_center', // No distinct belt icon, reuse fitness
        sleeves: 'accessibility_new',
        straps: 'link',
        other: 'inventory_2',
    }
    return icons[type] || 'inventory_2'
}

const formatDate = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    })
}
</script>
