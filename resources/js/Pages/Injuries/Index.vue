<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    activeInjuries: Array,
    history: Array,
})

const showAddForm = ref(false)
const editingInjury = ref(null)

const form = useForm({
    body_part: '',
    description: '',
    pain_level: 5,
    status: 'active',
    occurred_at: new Date().toISOString().substr(0, 10),
    notes: '',
})

const submit = () => {
    if (editingInjury.value) {
        form.put(route('injuries.update', editingInjury.value.id), {
            onSuccess: () => {
                cancelEdit()
            },
        })
    } else {
        form.post(route('injuries.store'), {
            onSuccess: () => {
                form.reset()
                form.pain_level = 5 // reset default
                form.occurred_at = new Date().toISOString().substr(0, 10)
                showAddForm.value = false
            },
        })
    }
}

const editInjury = (injury) => {
    editingInjury.value = injury
    form.body_part = injury.body_part
    form.description = injury.description
    form.pain_level = injury.pain_level
    form.status = injury.status
    form.occurred_at = injury.occurred_at
    form.notes = injury.notes
    showAddForm.value = true
}

const cancelEdit = () => {
    editingInjury.value = null
    form.reset()
    form.pain_level = 5
    form.occurred_at = new Date().toISOString().substr(0, 10)
    showAddForm.value = false
}

const deleteInjury = (id) => {
    if (confirm('Are you sure you want to delete this injury record?')) {
        router.delete(route('injuries.destroy', id))
    }
}

const getPainColor = (level) => {
    if (level >= 8) return 'from-red-500 to-red-700'
    if (level >= 5) return 'from-orange-500 to-red-500'
    return 'from-yellow-400 to-orange-500'
}
</script>

<template>
    <Head title="Injury Tracker" />

    <AuthenticatedLayout page-title="Injury Tracker">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm">
                <svg v-if="!showAddForm" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span v-else>Cancel</span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Injury Tracker</h2>
                <GlassButton @click="showAddForm = !showAddForm">
                    <span v-if="!showAddForm" class="flex items-center gap-2">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Log Injury
                    </span>
                    <span v-else>Cancel</span>
                </GlassButton>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up border-primary-500/30">
                <h3 class="text-text-main mb-4 font-semibold">{{ editingInjury ? 'Edit Injury' : 'Log New Injury' }}</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.body_part"
                        label="Body Part"
                        placeholder="e.g. Left Knee"
                        :error="form.errors.body_part"
                        required
                    />

                    <GlassInput
                        v-model="form.description"
                        label="Description"
                        placeholder="e.g. Sharp pain when squatting"
                        :error="form.errors.description"
                    />

                    <div>
                        <div class="mb-1 flex justify-between">
                            <label class="text-text-muted text-sm font-medium">Pain Level (1-10)</label>
                            <span class="font-bold text-primary-400">{{ form.pain_level }}</span>
                        </div>
                        <input
                            type="range"
                            v-model.number="form.pain_level"
                            min="1"
                            max="10"
                            class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-white/10 accent-primary-500"
                        />
                        <div class="mt-1 flex justify-between text-xs text-text-muted">
                            <span>Mild</span>
                            <span>Severe</span>
                        </div>
                        <p v-if="form.errors.pain_level" class="text-sm text-red-500">{{ form.errors.pain_level }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label class="text-text-muted block text-sm font-medium">Status</label>
                            <select
                                v-model="form.status"
                                class="text-text-main focus:border-primary-500 focus:ring-primary-500 w-full rounded-xl border-white/10 bg-white/5 p-2.5"
                            >
                                <option value="active">Active (Painful)</option>
                                <option value="recovering">Recovering (Improving)</option>
                                <option value="healed">Healed</option>
                            </select>
                             <p v-if="form.errors.status" class="text-sm text-red-500">{{ form.errors.status }}</p>
                        </div>

                         <GlassInput
                            v-model="form.occurred_at"
                            type="date"
                            label="Date Occurred"
                            :error="form.errors.occurred_at"
                            required
                        />
                    </div>

                    <GlassInput
                        v-model="form.notes"
                        label="Notes / Rehab Plan"
                        placeholder="e.g. Ice twice a day, avoid heavy squats"
                        :error="form.errors.notes"
                    />

                    <div class="flex gap-3">
                         <GlassButton type="submit" variant="primary" class="flex-1" :loading="form.processing">
                            {{ editingInjury ? 'Update' : 'Save' }}
                        </GlassButton>
                        <GlassButton type="button" @click="cancelEdit" v-if="editingInjury">
                            Cancel
                        </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- Active Injuries -->
            <div>
                <h3 class="text-text-muted mb-4 text-sm font-bold uppercase tracking-wider">Active Injuries</h3>

                <div v-if="activeInjuries.length === 0" class="text-center py-8">
                     <p class="text-text-muted">No active injuries. Stay safe!</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <GlassCard
                        v-for="injury in activeInjuries"
                        :key="injury.id"
                        class="group relative overflow-hidden transition hover:bg-white/10"
                    >
                        <div class="absolute top-0 right-0 h-24 w-24 translate-x-8 translate-y--8 transform rounded-full bg-gradient-to-br opacity-20 blur-xl" :class="getPainColor(injury.pain_level)"></div>

                        <div class="relative z-10">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h4 class="text-lg font-bold text-text-main">{{ injury.body_part }}</h4>
                                    <p class="text-sm text-primary-500">{{ injury.status === 'recovering' ? 'Recovering' : 'Active' }}</p>
                                </div>
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-text-main shadow-lg backdrop-blur-md">
                                    {{ injury.pain_level }}
                                </div>
                            </div>

                            <p class="mt-2 text-sm text-text-muted line-clamp-2">{{ injury.description || 'No description' }}</p>

                            <div class="mt-4 flex items-center justify-between text-xs text-text-muted">
                                <span>Since: {{ new Date(injury.occurred_at).toLocaleDateString() }}</span>
                            </div>

                             <div class="mt-4 flex justify-end gap-2 opacity-0 transition group-hover:opacity-100">
                                <button @click="editInjury(injury)" class="text-primary-400 hover:text-white">Edit</button>
                                <button @click="deleteInjury(injury.id)" class="text-red-400 hover:text-red-300">Delete</button>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </div>

            <!-- History -->
            <div v-if="history.length > 0">
                 <h3 class="text-text-muted mb-4 text-sm font-bold uppercase tracking-wider">History (Healed)</h3>
                 <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5 backdrop-blur-md">
                    <table class="w-full text-left text-sm text-text-muted">
                        <thead class="bg-white/5 text-xs uppercase text-text-main">
                            <tr>
                                <th class="px-6 py-3">Body Part</th>
                                <th class="px-6 py-3">Recovered</th>
                                <th class="px-6 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            <tr v-for="injury in history" :key="injury.id" class="hover:bg-white/5">
                                <td class="px-6 py-4 font-medium text-text-main">{{ injury.body_part }}</td>
                                <td class="px-6 py-4">{{ injury.healed_at ? new Date(injury.healed_at).toLocaleDateString() : '-' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button @click="editInjury(injury)" class="mr-3 text-primary-400 hover:text-white">Edit</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                 </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
