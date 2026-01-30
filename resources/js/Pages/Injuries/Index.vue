<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    injuries: Object, // Grouped by status: { active: [], recovering: [], healed: [] }
})

const showAddForm = ref(false)
const editingInjury = ref(null)

const form = useForm({
    body_part: '',
    description: '',
    severity: 5,
    status: 'active',
    occurred_at: new Date().toISOString().substr(0, 10),
    recovered_at: '',
    notes: '',
})

const startEdit = (injury) => {
    editingInjury.value = injury
    form.body_part = injury.body_part
    form.description = injury.description
    form.severity = injury.severity
    form.status = injury.status
    form.occurred_at = injury.occurred_at
    form.recovered_at = injury.recovered_at || ''
    form.notes = injury.notes || ''
    showAddForm.value = true
}

const cancelEdit = () => {
    showAddForm.value = false
    editingInjury.value = null
    form.reset()
    form.clearErrors()
}

const submit = () => {
    if (editingInjury.value) {
        form.put(route('injuries.update', editingInjury.value.id), {
            onSuccess: () => cancelEdit(),
        })
    } else {
        form.post(route('injuries.store'), {
            onSuccess: () => cancelEdit(),
        })
    }
}

const deleteInjury = (id) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette blessure ?')) {
        useForm({}).delete(route('injuries.destroy', id))
    }
}

const markHealed = (injury) => {
    useForm({
        ...injury,
        status: 'healed',
        recovered_at: new Date().toISOString().substr(0, 10),
    }).put(route('injuries.update', injury.id))
}
</script>

<template>
    <Head title="Blessures" />

    <AuthenticatedLayout page-title="Blessures" show-back back-route="tools.index">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm">
                <svg v-if="!showAddForm" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-text-main">Blessures</h2>
                <GlassButton @click="showAddForm = !showAddForm">
                    <svg v-if="!showAddForm" class="mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <svg v-else class="mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    {{ showAddForm ? 'Fermer' : 'Ajouter' }}
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Add/Edit Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="mb-4 font-semibold text-text-main">
                    {{ editingInjury ? 'Modifier la blessure' : 'Nouvelle blessure' }}
                </h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <GlassInput
                            v-model="form.body_part"
                            label="Partie du corps"
                            placeholder="Genou droit"
                            :error="form.errors.body_part"
                            required
                        />
                        <GlassInput
                            v-model="form.description"
                            label="Description"
                            placeholder="Douleur rotulienne"
                            :error="form.errors.description"
                            required
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <GlassInput
                            v-model="form.occurred_at"
                            type="date"
                            label="Date de début"
                            :error="form.errors.occurred_at"
                            required
                        />
                         <div class="flex flex-col gap-1.5">
                            <label class="ml-1 text-sm font-bold text-text-muted">Gravité (1-10)</label>
                            <input
                                type="range"
                                v-model.number="form.severity"
                                min="1"
                                max="10"
                                class="h-10 w-full cursor-pointer appearance-none rounded-lg bg-white/20"
                            />
                            <div class="flex justify-between px-1 text-xs text-text-muted">
                                <span>Léger</span>
                                <span class="font-bold text-electric-orange">{{ form.severity }}</span>
                                <span>Sévère</span>
                            </div>
                            <div v-if="form.errors.severity" class="text-xs text-red-500">{{ form.errors.severity }}</div>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="ml-1 text-sm font-bold text-text-muted">Statut</label>
                            <select
                                v-model="form.status"
                                class="glass-input h-10 w-full rounded-xl border border-white/20 bg-white/10 px-3 text-text-main focus:border-electric-orange focus:ring-electric-orange dark:bg-slate-800/50"
                            >
                                <option value="active">Active</option>
                                <option value="recovering">En récupération</option>
                                <option value="healed">Guérie</option>
                            </select>
                            <div v-if="form.errors.status" class="text-xs text-red-500">{{ form.errors.status }}</div>
                        </div>
                    </div>

                    <GlassInput
                        v-if="form.status === 'healed'"
                        v-model="form.recovered_at"
                        type="date"
                        label="Date de guérison"
                        :error="form.errors.recovered_at"
                    />

                    <GlassInput
                        v-model="form.notes"
                        label="Notes"
                        placeholder="Détails supplémentaires..."
                        :error="form.errors.notes"
                    />

                    <div class="flex gap-3">
                         <GlassButton type="button" variant="secondary" class="flex-1" @click="cancelEdit">
                            Annuler
                        </GlassButton>
                        <GlassButton type="submit" variant="primary" class="flex-1" :loading="form.processing">
                            Enregistrer
                        </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- Active Injuries -->
            <div class="space-y-4">
                <h3 class="flex items-center gap-2 font-display text-xs font-black uppercase tracking-[0.2em] text-electric-orange">
                    <span class="material-symbols-outlined text-lg">medical_services</span>
                    Blessures Actives
                </h3>

                <div v-if="!injuries.active && !injuries.recovering" class="text-center text-sm text-text-muted py-8">
                    Aucune blessure active. Continue comme ça ! 💪
                </div>

                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <template v-for="status in ['active', 'recovering']">
                        <GlassCard
                            v-for="injury in (injuries[status] || [])"
                            :key="injury.id"
                            class="relative overflow-hidden transition-all hover:bg-white/5"
                            :class="{'border-l-4 border-l-red-500': injury.status === 'active', 'border-l-4 border-l-yellow-500': injury.status === 'recovering'}"
                        >
                            <div class="flex items-start justify-between">
                                <div>
                                    <h4 class="text-lg font-bold text-text-main">{{ injury.body_part }}</h4>
                                    <p class="text-sm font-medium text-text-muted">{{ injury.description }}</p>
                                    <div class="mt-2 flex items-center gap-2 text-xs">
                                        <span class="rounded-full bg-black/10 px-2 py-0.5 font-bold dark:bg-white/10">
                                            Sev: {{ injury.severity }}/10
                                        </span>
                                        <span class="text-text-muted">
                                            Depuis le {{ new Date(injury.occurred_at).toLocaleDateString() }}
                                        </span>
                                    </div>
                                    <div v-if="injury.notes" class="mt-2 text-xs italic text-text-muted/80">
                                        "{{ injury.notes }}"
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2">
                                    <button
                                        @click="startEdit(injury)"
                                        class="text-text-muted hover:text-electric-orange"
                                        title="Modifier"
                                    >
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <button
                                        @click="deleteInjury(injury.id)"
                                        class="text-text-muted hover:text-red-500"
                                        title="Supprimer"
                                    >
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 flex justify-end">
                                <button
                                    v-if="injury.status !== 'healed'"
                                    @click="markHealed(injury)"
                                    class="flex items-center gap-1 rounded-lg bg-green-500/10 px-3 py-1.5 text-xs font-bold text-green-600 transition hover:bg-green-500/20"
                                >
                                    <span class="material-symbols-outlined text-sm">check_circle</span>
                                    Marquer guérie
                                </button>
                            </div>
                        </GlassCard>
                    </template>
                </div>
            </div>

            <!-- History (Healed) -->
            <div v-if="injuries.healed && injuries.healed.length > 0" class="space-y-4 pt-4 border-t border-white/10">
                <h3 class="flex items-center gap-2 font-display text-xs font-black uppercase tracking-[0.2em] text-green-600">
                    <span class="material-symbols-outlined text-lg">history</span>
                    Historique
                </h3>

                <div class="space-y-2">
                    <GlassCard
                        v-for="injury in injuries.healed"
                        :key="injury.id"
                        padding="p-4"
                        class="opacity-75 transition-opacity hover:opacity-100"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="flex items-baseline gap-2">
                                    <span class="font-bold text-text-main">{{ injury.body_part }}</span>
                                    <span class="text-sm text-text-muted">- {{ injury.description }}</span>
                                </div>
                                <div class="text-xs text-text-muted">
                                    {{ new Date(injury.occurred_at).toLocaleDateString() }} → {{ new Date(injury.recovered_at).toLocaleDateString() }}
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button @click="startEdit(injury)" class="text-text-muted hover:text-text-main">
                                    <span class="material-symbols-outlined text-base">edit</span>
                                </button>
                                <button @click="deleteInjury(injury.id)" class="text-text-muted hover:text-red-500">
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
