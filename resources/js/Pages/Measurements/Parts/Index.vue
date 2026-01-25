<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    latestMeasurements: Array,
    commonParts: Array,
})

const showAddForm = ref(false)

const form = useForm({
    part: '',
    value: '',
    unit: 'cm',
    measured_at: new Date().toISOString().substr(0, 10),
    notes: '',
})

const submit = () => {
    form.post(route('body-parts.store'), {
        onSuccess: () => {
            form.reset('value', 'notes')
            showAddForm.value = false
        },
    })
}

const selectCommonPart = (part) => {
    form.part = part
}
</script>

<template>
    <Head title="Measurements" />

    <AuthenticatedLayout page-title="Measurements">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm" aria-label="Add measurement">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Measurements</h2>
                <GlassButton @click="showAddForm = !showAddForm">
                    <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Add Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="text-text-main mb-4 font-semibold">New Measurement</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="text-text-muted mb-1 block text-sm font-medium">Body Part</label>
                        <div class="mb-2 flex flex-wrap gap-2">
                            <button
                                v-for="part in commonParts"
                                :key="part"
                                type="button"
                                @click="selectCommonPart(part)"
                                :class="[
                                    'rounded-full px-3 py-1 text-xs transition',
                                    form.part === part
                                        ? 'bg-primary-500 text-white'
                                        : 'text-text-muted bg-white/5 hover:bg-white/10',
                                ]"
                            >
                                {{ part }}
                            </button>
                        </div>
                        <GlassInput v-model="form.part" placeholder="Ex: Waist" :error="form.errors.part" required />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.value"
                            type="number"
                            step="0.1"
                            label="Value"
                            placeholder="0.00"
                            :error="form.errors.value"
                            inputmode="decimal"
                            required
                        />
                        <div class="space-y-1">
                            <label class="text-text-muted block text-sm font-medium">Unit</label>
                            <select
                                v-model="form.unit"
                                class="text-text-main focus:border-primary-500 focus:ring-primary-500 w-full rounded-xl border-white/10 bg-white/5"
                            >
                                <option value="cm">cm</option>
                                <option value="in">in</option>
                            </select>
                        </div>
                    </div>

                    <GlassInput
                        v-model="form.measured_at"
                        type="date"
                        label="Date"
                        :error="form.errors.measured_at"
                        required
                    />

                    <GlassInput v-model="form.notes" label="Notes (optional)" :error="form.errors.notes" />

                    <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                        Save
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- Grid -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="item in latestMeasurements"
                    :key="item.part"
                    :href="route('body-parts.show', { part: item.part })"
                    class="block"
                >
                    <GlassCard class="h-full transition hover:scale-[1.02] hover:bg-white/10">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-text-main font-bold">{{ item.part }}</h3>
                                <div
                                    class="mt-1 bg-gradient-to-r from-purple-400 to-pink-400 bg-clip-text text-2xl font-bold text-transparent"
                                >
                                    {{ item.current }} <span class="text-text-muted text-sm">{{ item.unit }}</span>
                                </div>
                                <div class="text-text-muted mt-1 text-xs">
                                    {{ new Date(item.date).toLocaleDateString() }}
                                </div>
                            </div>
                            <div
                                v-if="item.diff !== 0"
                                :class="[
                                    'flex items-center text-sm font-bold',
                                    item.diff > 0 ? 'text-green-400' : 'text-red-400',
                                ]"
                            >
                                {{ item.diff > 0 ? '+' : '' }}{{ item.diff }}
                            </div>
                        </div>
                    </GlassCard>
                </Link>

                <!-- Empty State -->
                <div v-if="latestMeasurements.length === 0 && !showAddForm" class="col-span-full py-12 text-center">
                    <p class="text-text-muted">No measurements recorded.</p>
                    <GlassButton @click="showAddForm = true" class="mt-4"> Start </GlassButton>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
