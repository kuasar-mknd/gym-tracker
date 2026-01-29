<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    injuries: Array,
})

const showAddForm = ref(false)
const editingInjury = ref(null)

const form = useForm({
    title: '',
    body_part: '',
    status: 'active',
    pain_level: 1,
    occurred_at: new Date().toISOString().substr(0, 10),
    healed_at: '',
    notes: '',
})

const editForm = useForm({
    title: '',
    body_part: '',
    status: '',
    pain_level: 1,
    occurred_at: '',
    healed_at: '',
    notes: '',
})

const submit = () => {
    form.post(route('injuries.store'), {
        onSuccess: () => {
            form.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (injury) => {
    editingInjury.value = injury
    editForm.title = injury.title
    editForm.body_part = injury.body_part
    editForm.status = injury.status
    editForm.pain_level = injury.pain_level
    editForm.occurred_at = injury.occurred_at
    editForm.healed_at = injury.healed_at || ''
    editForm.notes = injury.notes || ''
}

const cancelEdit = () => {
    editingInjury.value = null
    editForm.reset()
}

const update = () => {
    editForm.put(route('injuries.update', editingInjury.value.id), {
        onSuccess: () => {
            editingInjury.value = null
            editForm.reset()
        },
    })
}

const destroy = (id) => {
    if (confirm('Are you sure you want to delete this injury record?')) {
        useForm({}).delete(route('injuries.destroy', id))
    }
}

const statusColors = {
    active: 'text-red-500 bg-red-500/10 border-red-500/20',
    recovering: 'text-yellow-500 bg-yellow-500/10 border-yellow-500/20',
    healed: 'text-green-500 bg-green-500/10 border-green-500/20',
}

const statusLabels = {
    active: 'Active',
    recovering: 'Recovering',
    healed: 'Healed',
}
</script>

<template>
    <Head title="Injuries" />

    <AuthenticatedLayout page-title="Injuries">
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
                <h2 class="text-xl font-semibold text-text-main">Injuries</h2>
                <GlassButton @click="showAddForm = !showAddForm">
                    <svg
                        class="mr-2 h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    {{ showAddForm ? 'Cancel' : 'Log Injury' }}
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Add Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="mb-4 font-semibold text-text-main">Log New Injury</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <GlassInput v-model="form.title" label="Title" placeholder="e.g. Left Knee Strain" :error="form.errors.title" required />
                        <GlassInput v-model="form.body_part" label="Body Part" placeholder="e.g. Knee" :error="form.errors.body_part" required />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                         <!-- Select for Status -->
                         <div class="space-y-1">
                            <label class="block text-xs font-bold uppercase tracking-wider text-text-muted">Status</label>
                            <select v-model="form.status" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-text-main backdrop-blur-md focus:border-accent-primary focus:ring-accent-primary">
                                <option value="active">Active</option>
                                <option value="recovering">Recovering</option>
                                <option value="healed">Healed</option>
                            </select>
                            <div v-if="form.errors.status" class="text-xs text-red-400">{{ form.errors.status }}</div>
                         </div>

                        <GlassInput v-model="form.pain_level" type="number" min="1" max="10" label="Pain Level (1-10)" :error="form.errors.pain_level" required />
                        <GlassInput v-model="form.occurred_at" type="date" label="Date Occurred" :error="form.errors.occurred_at" required />
                    </div>

                    <GlassInput v-model="form.notes" label="Notes" type="textarea" :error="form.errors.notes" />

                    <div class="flex justify-end gap-2">
                        <GlassButton type="button" variant="secondary" @click="showAddForm = false">Cancel</GlassButton>
                        <GlassButton type="submit" variant="primary" :loading="form.processing">Save</GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- List -->
            <div class="space-y-4">
                <div v-if="injuries.length === 0" class="py-12 text-center text-text-muted">
                    No injuries recorded. Stay safe!
                </div>

                <GlassCard v-for="injury in injuries" :key="injury.id" class="group relative animate-slide-up">
                    <div v-if="editingInjury?.id === injury.id">
                        <!-- Edit Mode -->
                         <form @submit.prevent="update" class="space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <GlassInput v-model="editForm.title" label="Title" :error="editForm.errors.title" required />
                                <GlassInput v-model="editForm.body_part" label="Body Part" :error="editForm.errors.body_part" required />
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div class="space-y-1">
                                    <label class="block text-xs font-bold uppercase tracking-wider text-text-muted">Status</label>
                                    <select v-model="editForm.status" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-text-main backdrop-blur-md focus:border-accent-primary focus:ring-accent-primary">
                                        <option value="active">Active</option>
                                        <option value="recovering">Recovering</option>
                                        <option value="healed">Healed</option>
                                    </select>
                                </div>
                                <GlassInput v-model="editForm.pain_level" type="number" min="1" max="10" label="Pain Level" :error="editForm.errors.pain_level" required />
                                <div class="space-y-1">
                                     <label class="block text-xs font-bold uppercase tracking-wider text-text-muted">Healed At</label>
                                     <input type="date" v-model="editForm.healed_at" class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-text-main backdrop-blur-md focus:border-accent-primary focus:ring-accent-primary text-sm" />
                                </div>
                            </div>
                            <GlassInput v-model="editForm.notes" label="Notes" type="textarea" :error="editForm.errors.notes" />
                            <div class="flex justify-end gap-2">
                                <GlassButton type="button" variant="secondary" @click="cancelEdit">Cancel</GlassButton>
                                <GlassButton type="submit" variant="primary" :loading="editForm.processing">Update</GlassButton>
                            </div>
                        </form>
                    </div>
                    <div v-else>
                        <!-- View Mode -->
                        <div class="flex items-start justify-between">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-lg font-bold text-text-main">{{ injury.title }}</h3>
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-bold border', statusColors[injury.status]]">
                                        {{ statusLabels[injury.status] }}
                                    </span>
                                </div>
                                <p class="text-sm text-text-muted">
                                    {{ injury.body_part }} • Pain: <span class="font-bold" :class="injury.pain_level > 5 ? 'text-red-400' : 'text-yellow-400'">{{ injury.pain_level }}/10</span>
                                </p>
                                <p class="text-xs text-text-muted/70">
                                    Occurred: {{ new Date(injury.occurred_at).toLocaleDateString() }}
                                    <span v-if="injury.healed_at"> • Healed: {{ new Date(injury.healed_at).toLocaleDateString() }}</span>
                                </p>
                                <p v-if="injury.notes" class="mt-2 text-sm text-text-main/80">{{ injury.notes }}</p>
                            </div>
                            <div class="flex gap-2 opacity-0 transition group-hover:opacity-100">
                                <button @click="startEdit(injury)" class="text-text-muted hover:text-accent-primary">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button @click="destroy(injury.id)" class="text-text-muted hover:text-red-400">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
