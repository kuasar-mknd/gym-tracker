<template>
    <AuthenticatedLayout page-title="Warmup Calculator" show-back back-route="tools.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('tools.index')"
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-text-muted shadow-sm transition-colors hover:text-electric-orange"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-xl font-semibold text-text-main">Warmup Calculator</h2>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Calculator Input -->
            <div class="space-y-6">
                <GlassCard>
                    <div class="space-y-6 p-6">
                        <h2 class="text-xl font-bold text-text-main">Calculate</h2>

                        <div class="space-y-4">
                            <div class="space-y-2">
                                <InputLabel value="Working Weight (kg)" />
                                <GlassInput
                                    type="number"
                                    v-model="targetWeight"
                                    placeholder="e.g. 100"
                                    min="0"
                                    step="0.5"
                                    class="w-full"
                                />
                            </div>

                            <div class="space-y-2">
                                <InputLabel value="Warmup Strategy" />
                                <div class="relative">
                                    <select
                                        v-model="selectedPreferenceId"
                                        class="w-full appearance-none rounded-xl border border-slate-300 bg-white/50 p-3 text-text-main backdrop-blur-sm transition focus:border-electric-orange focus:outline-none focus:ring-1 focus:ring-electric-orange"
                                    >
                                        <option :value="null">Standard (Bar -> 40% -> 60% -> 80%)</option>
                                        <option v-for="pref in preferences" :key="pref.id" :value="pref.id">
                                            {{ pref.name }}
                                        </option>
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-text-muted">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </GlassCard>

                <!-- Manage Preferences -->
                <GlassCard class="overflow-hidden">
                    <div class="border-b border-slate-100 bg-slate-50/50 p-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-text-main">My Strategies</h3>
                            <GlassButton size="sm" @click="openCreateModal">
                                <svg class="mr-1 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Create
                            </GlassButton>
                        </div>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div v-if="preferences.length === 0" class="p-6 text-center text-sm text-text-muted">
                            No custom strategies found.
                        </div>
                        <div
                            v-for="pref in preferences"
                            :key="pref.id"
                            class="flex items-center justify-between p-4 transition hover:bg-white/50"
                        >
                            <span class="font-medium text-text-main">{{ pref.name }}</span>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="editPreference(pref)"
                                    class="rounded-lg p-2 text-text-muted transition hover:bg-slate-100 hover:text-electric-orange"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                </button>
                                <button
                                    @click="deletePreference(pref)"
                                    class="rounded-lg p-2 text-text-muted transition hover:bg-slate-100 hover:text-red-500"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </GlassCard>
            </div>

            <!-- Results Table -->
            <div>
                <GlassCard class="h-full">
                    <div class="p-6">
                        <h2 class="mb-4 text-xl font-bold text-text-main">Sets to Perform</h2>

                        <div v-if="warmupSets.length > 0">
                            <div class="overflow-hidden rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                                <table class="w-full text-left text-sm text-text-muted">
                                    <thead class="border-b border-white/20 bg-white/10 text-xs uppercase text-text-main">
                                        <tr>
                                            <th class="px-6 py-3 font-medium">Type</th>
                                            <th class="px-6 py-3 font-medium">Weight</th>
                                            <th class="px-6 py-3 font-medium">Reps</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10 bg-transparent">
                                        <tr
                                            v-for="(set, index) in warmupSets"
                                            :key="index"
                                            class="transition-colors duration-200 hover:bg-white/20"
                                        >
                                            <td class="px-6 py-4">
                                                <span v-if="set.is_working_set" class="font-bold text-electric-orange">Working Set</span>
                                                <span v-else>{{ set.label }}</span>
                                            </td>
                                            <td class="px-6 py-4 font-bold text-text-main">{{ set.weight }} kg</td>
                                            <td class="px-6 py-4">{{ set.reps }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-white/20 bg-white/10 p-12 text-text-muted backdrop-blur-sm"
                        >
                            <span class="text-4xl mb-2">🏋️</span>
                            <span>Enter a weight to see sets</span>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>

        <!-- Preference Modal -->
        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-text-main">
                    {{ editingPreference ? 'Edit Strategy' : 'New Strategy' }}
                </h2>

                <div class="mt-6 space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Name"
                        placeholder="e.g. Heavy Squat"
                        :error="form.errors.name"
                    />

                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-text-main">Sets</label>
                        <div v-for="(set, index) in form.sets_config" :key="index" class="flex gap-2 items-start">
                            <div class="flex-1">
                                <select
                                    v-model="set.type"
                                    class="w-full appearance-none rounded-lg border border-slate-300 bg-white p-2 text-sm text-text-main focus:border-electric-orange focus:outline-none focus:ring-1 focus:ring-electric-orange"
                                >
                                    <option value="bar">Empty Bar</option>
                                    <option value="percentage">% of Target</option>
                                    <option value="weight">Fixed Weight</option>
                                </select>
                            </div>
                            <div class="w-24">
                                <GlassInput
                                    v-if="set.type !== 'bar'"
                                    type="number"
                                    v-model="set.value"
                                    :placeholder="set.type === 'percentage' ? '0.5' : 'kg'"
                                    step="0.05"
                                    class="h-10 text-sm"
                                />
                            </div>
                            <div class="w-20">
                                <GlassInput
                                    type="number"
                                    v-model="set.reps"
                                    placeholder="Reps"
                                    class="h-10 text-sm"
                                />
                            </div>
                            <button
                                @click="removeSetLine(index)"
                                class="mt-1 text-text-muted hover:text-red-500"
                                type="button"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <button
                            type="button"
                            @click="addSetLine"
                            class="text-sm font-medium text-electric-orange hover:text-electric-orange/80"
                        >
                            + Add Set
                        </button>
                        <p v-if="form.errors.sets_config" class="text-xs text-red-500">{{ form.errors.sets_config }}</p>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <GlassButton variant="secondary" @click="closeModal">Cancel</GlassButton>
                        <GlassButton variant="primary" @click="savePreference" :loading="form.processing">
                            Save
                        </GlassButton>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
    preferences: Array,
})

const targetWeight = ref('')
const selectedPreferenceId = ref(null)
const showModal = ref(false)
const editingPreference = ref(null)

const form = useForm({
    name: '',
    sets_config: [
        { type: 'bar', value: null, reps: 10 },
        { type: 'percentage', value: 0.5, reps: 5 },
    ],
})

const standardPreference = {
    sets_config: [
        { type: 'bar', value: null, reps: 10 },
        { type: 'percentage', value: 0.4, reps: 5 },
        { type: 'percentage', value: 0.6, reps: 3 },
        { type: 'percentage', value: 0.8, reps: 2 },
    ]
}

const activePreference = computed(() => {
    if (!selectedPreferenceId.value) return standardPreference
    return props.preferences.find(p => p.id === selectedPreferenceId.value) || standardPreference
})

const warmupSets = computed(() => {
    const w = parseFloat(targetWeight.value)
    if (!w || w <= 0) return []

    const sets = []
    const config = activePreference.value.sets_config || []

    config.forEach(step => {
        let weight = 0
        let label = ''

        if (step.type === 'bar') {
            weight = 20 // Standard Olympic Bar
            label = 'Empty Bar'
        } else if (step.type === 'percentage') {
            weight = w * parseFloat(step.value)
            label = `${Math.round(step.value * 100)}%`
        } else if (step.type === 'weight') {
            weight = parseFloat(step.value)
            label = 'Fixed'
        }

        // Round to nearest 2.5kg
        weight = Math.round(weight / 2.5) * 2.5

        if (weight >= w) return // Don't show warmup sets heavier than target

        sets.push({
            label,
            weight,
            reps: step.reps,
            is_working_set: false
        })
    })

    // Add working set
    sets.push({
        label: 'Working Set',
        weight: w,
        reps: 'Target',
        is_working_set: true
    })

    return sets
})

const openCreateModal = () => {
    editingPreference.value = null
    form.reset()
    form.clearErrors()
    form.sets_config = [
        { type: 'bar', value: null, reps: 10 },
        { type: 'percentage', value: 0.5, reps: 5 },
    ]
    showModal.value = true
}

const editPreference = (pref) => {
    editingPreference.value = pref
    form.name = pref.name
    form.sets_config = JSON.parse(JSON.stringify(pref.sets_config)) // Deep copy
    form.clearErrors()
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
}

const addSetLine = () => {
    form.sets_config.push({ type: 'percentage', value: 0.5, reps: 5 })
}

const removeSetLine = (index) => {
    form.sets_config.splice(index, 1)
}

const savePreference = () => {
    if (editingPreference.value) {
        form.put(route('warmup.update', editingPreference.value.id), {
            onSuccess: () => closeModal(),
        })
    } else {
        form.post(route('warmup.store'), {
            onSuccess: () => closeModal(),
        })
    }
}

const deletePreference = (pref) => {
    if (confirm('Delete this strategy?')) {
        useForm({}).delete(route('warmup.destroy', pref.id))
    }
}
</script>
