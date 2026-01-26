<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    injuries: Array,
})

const showAddForm = ref(false)
const editingInjury = ref(null)

const form = useForm({
    body_part: '',
    description: '',
    status: 'active',
    injured_at: new Date().toISOString().substr(0, 10),
    healed_at: null,
    notes: '',
})

const statuses = [
    { value: 'active', label: '🔴 Actif' },
    { value: 'recovering', label: '🟠 Récupération' },
    { value: 'healed', label: '🟢 Guéri' },
]

const resetForm = () => {
    form.reset()
    form.injured_at = new Date().toISOString().substr(0, 10)
    editingInjury.value = null
    showAddForm.value = false
}

const openAddForm = () => {
    resetForm()
    showAddForm.value = true
}

const editInjury = (injury) => {
    form.body_part = injury.body_part
    form.description = injury.description
    form.status = injury.status
    form.injured_at = injury.injured_at
    form.healed_at = injury.healed_at
    form.notes = injury.notes
    editingInjury.value = injury
    showAddForm.value = true
}

const submit = () => {
    if (editingInjury.value) {
        form.put(route('injuries.update', editingInjury.value.id), {
            onSuccess: resetForm,
        })
    } else {
        form.post(route('injuries.store'), {
            onSuccess: resetForm,
        })
    }
}

const deleteInjury = (id) => {
    if (confirm('Supprimer cette blessure ?')) {
        useForm({}).delete(route('injuries.destroy', id))
    }
}

const activeInjuries = computed(() => props.injuries.filter(i => i.status !== 'healed'))
const healedInjuries = computed(() => props.injuries.filter(i => i.status === 'healed'))

const formatDate = (dateStr) => {
    if (!dateStr) return '-'
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    })
}
</script>

<template>
    <Head title="Suivi Blessures" />

    <AuthenticatedLayout page-title="Blessures">
        <template #header-actions>
            <GlassButton size="sm" @click="openAddForm">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-text-main">Suivi Blessures</h2>
                <GlassButton @click="openAddForm">
                    <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Add/Edit Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-semibold text-text-main">{{ editingInjury ? "Modifier" : "Nouvelle blessure" }}</h3>
                    <button @click="showAddForm = false" class="text-text-muted hover:text-text-main">✕</button>
                </div>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <GlassInput v-model="form.body_part" label="Partie du corps" placeholder="ex: Genou droit" :error="form.errors.body_part" required />
                        <GlassInput v-model="form.description" label="Description" placeholder="ex: Douleur rotulienne" :error="form.errors.description" required />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-text-muted mb-1">Statut</label>
                            <div class="flex gap-2">
                                <button
                                    v-for="status in statuses"
                                    :key="status.value"
                                    type="button"
                                    @click="form.status = status.value"
                                    :class="[
                                        'flex-1 p-2 rounded-lg border text-sm transition',
                                        form.status === status.value
                                            ? 'bg-accent-primary text-white border-transparent'
                                            : 'bg-white/50 text-text-muted border-slate-200 hover:bg-slate-50'
                                    ]"
                                >
                                    {{ status.label }}
                                </button>
                            </div>
                            <div v-if="form.errors.status" class="text-red-400 text-xs mt-1">{{ form.errors.status }}</div>
                        </div>
                         <GlassInput v-model="form.injured_at" type="date" label="Date blessure" :error="form.errors.injured_at" required />
                    </div>

                    <div v-if="form.status === 'healed'">
                        <GlassInput v-model="form.healed_at" type="date" label="Date guérison (optionnel)" :error="form.errors.healed_at" />
                    </div>

                    <GlassInput v-model="form.notes" label="Notes / Réhab" placeholder="Exercices à éviter, protocole..." :error="form.errors.notes" />

                    <div class="flex justify-end gap-3">
                        <GlassButton type="button" variant="secondary" @click="showAddForm = false">Annuler</GlassButton>
                        <GlassButton type="submit" variant="primary" :loading="form.processing">Enregistrer</GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- Active Injuries -->
            <div v-if="activeInjuries.length > 0" class="animate-slide-up">
                <h3 class="font-display text-xs font-black uppercase tracking-[0.2em] text-red-500 mb-3">Blessures Actives</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <GlassCard v-for="injury in activeInjuries" :key="injury.id" padding="p-4" class="group border-l-4" :class="injury.status === 'recovering' ? 'border-l-orange-400' : 'border-l-red-500'">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="font-bold text-text-main">{{ injury.body_part }}</h4>
                                <p class="text-sm text-text-muted">{{ injury.description }}</p>
                                <div class="mt-2 flex items-center gap-2 text-xs">
                                    <span class="font-mono bg-white/50 px-1.5 py-0.5 rounded">{{ formatDate(injury.injured_at) }}</span>
                                    <span v-if="injury.status === 'recovering'" class="text-orange-500 font-bold">Récupération</span>
                                </div>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                                <button @click="editInjury(injury)" class="p-1 text-text-muted hover:text-text-main rounded hover:bg-white/50">
                                    <span class="material-symbols-outlined text-lg">edit</span>
                                </button>
                                <button @click="deleteInjury(injury.id)" class="p-1 text-text-muted hover:text-red-500 rounded hover:bg-white/50">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                        <div v-if="injury.notes" class="mt-3 text-sm text-text-main/80 bg-slate-50/50 p-2 rounded-lg">
                            {{ injury.notes }}
                        </div>
                    </GlassCard>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="activeInjuries.length === 0 && healedInjuries.length === 0 && !showAddForm" class="text-center py-12 animate-fade-in">
                <div class="text-5xl mb-4">🩺</div>
                <h3 class="text-lg font-medium text-text-main">Aucune blessure signalée</h3>
                <p class="text-text-muted">Tout va bien ! Utilisez ce suivi si besoin.</p>
                <GlassButton class="mt-4" @click="openAddForm">Signaler une blessure</GlassButton>
            </div>

            <!-- Healed History -->
            <div v-if="healedInjuries.length > 0" class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="font-display text-xs font-black uppercase tracking-[0.2em] text-emerald-600 mb-3">Historique Guérisons</h3>
                <div class="space-y-2">
                    <GlassCard v-for="injury in healedInjuries" :key="injury.id" padding="p-3" class="opacity-75 hover:opacity-100 transition group">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <div class="bg-emerald-100 text-emerald-600 rounded-full p-1">
                                    <span class="material-symbols-outlined text-base">check</span>
                                </div>
                                <div>
                                    <div class="font-bold text-text-main text-sm">{{ injury.body_part }} <span class="font-normal text-text-muted">- {{ injury.description }}</span></div>
                                    <div class="text-xs text-text-muted">
                                        {{ formatDate(injury.injured_at) }} ➔ {{ formatDate(injury.healed_at) }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition">
                                <button @click="editInjury(injury)" class="p-1 text-text-muted hover:text-text-main">
                                    <span class="material-symbols-outlined text-base">edit</span>
                                </button>
                                <button @click="deleteInjury(injury.id)" class="p-1 text-text-muted hover:text-red-500">
                                    <span class="material-symbols-outlined text-base">delete</span>
                                </button>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
