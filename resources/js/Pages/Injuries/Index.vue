<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import Modal from '@/Components/Modal.vue'
import InjuryForm from './Partials/InjuryForm.vue'
import { ref } from 'vue'

const props = defineProps({
    activeInjuries: Array,
    injuryHistory: Array,
})

const showModal = ref(false)
const editingInjury = ref(null)

const openCreateModal = () => {
    editingInjury.value = null
    showModal.value = true
}

const openEditModal = (injury) => {
    editingInjury.value = injury
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    editingInjury.value = null
}

const deleteInjury = (id) => {
    if (confirm('Are you sure you want to delete this injury log?')) {
        router.delete(route('injuries.destroy', id))
    }
}

const getSeverityColor = (severity) => {
    switch (severity) {
        case 'high': return 'text-red-500 bg-red-500/10'
        case 'medium': return 'text-orange-500 bg-orange-500/10'
        case 'low': return 'text-yellow-500 bg-yellow-500/10'
        default: return 'text-gray-500 bg-gray-500/10'
    }
}
</script>

<template>
    <Head title="Injury Tracker" />

    <AuthenticatedLayout page-title="Injury Tracker" show-back back-route="tools.index">
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                     <Link
                        :href="route('tools.index')"
                        class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                    >
                        <span class="material-symbols-outlined">arrow_back</span>
                    </Link>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">Injury Tracker</h2>
                </div>
                <GlassButton @click="openCreateModal">
                    <span class="material-symbols-outlined mr-2">add</span>
                    Log Injury
                </GlassButton>
            </div>
        </template>

        <div class="space-y-8">
            <!-- Active Injuries -->
            <section>
                <h3 class="text-text-main mb-4 text-lg font-bold">Active Injuries</h3>
                <div v-if="activeInjuries.length === 0" class="text-text-muted italic">
                    No active injuries. Stay safe!
                </div>
                <div v-else class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <GlassCard
                        v-for="injury in activeInjuries"
                        :key="injury.id"
                        class="relative overflow-hidden"
                        variant="iridescent"
                    >
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="font-bold text-lg text-text-main">{{ injury.body_part }}</h4>
                                <p class="text-sm text-text-muted">{{ injury.diagnosis || 'Undiagnosed' }}</p>
                            </div>
                            <span
                                class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider"
                                :class="getSeverityColor(injury.severity)"
                            >
                                {{ injury.severity }}
                            </span>
                        </div>

                        <div class="space-y-2 mb-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-text-muted">Pain Level</span>
                                <span class="font-bold">{{ injury.pain_level }}/10</span>
                            </div>
                             <div class="flex justify-between text-sm">
                                <span class="text-text-muted">Status</span>
                                <span class="capitalize text-electric-orange font-medium">{{ injury.status }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-text-muted">Since</span>
                                <span>{{ new Date(injury.occurred_at).toLocaleDateString(undefined, { timeZone: 'UTC' }) }}</span>
                            </div>
                        </div>

                        <div class="flex gap-2 mt-4 pt-4 border-t border-white/10">
                            <button
                                @click="openEditModal(injury)"
                                class="flex-1 py-2 text-sm font-medium text-text-main hover:bg-white/10 rounded-lg transition-colors"
                            >
                                Edit
                            </button>
                             <button
                                @click="deleteInjury(injury.id)"
                                class="flex-1 py-2 text-sm font-medium text-red-500 hover:bg-red-500/10 rounded-lg transition-colors"
                            >
                                Delete
                            </button>
                        </div>
                    </GlassCard>
                </div>
            </section>

            <!-- History -->
            <section v-if="injuryHistory.length > 0">
                <h3 class="text-text-main mb-4 text-lg font-bold">Recovery History</h3>
                <div class="space-y-4">
                    <GlassCard
                        v-for="injury in injuryHistory"
                        :key="injury.id"
                        class="flex items-center justify-between p-4 opacity-75 hover:opacity-100 transition-opacity"
                    >
                        <div>
                            <h4 class="font-medium text-text-main">{{ injury.body_part }}</h4>
                             <p class="text-xs text-text-muted">
                                {{ new Date(injury.occurred_at).toLocaleDateString(undefined, { timeZone: 'UTC' }) }} - {{ injury.healed_at ? new Date(injury.healed_at).toLocaleDateString(undefined, { timeZone: 'UTC' }) : '?' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                             <span class="px-2 py-1 bg-green-500/10 text-green-500 text-xs rounded-full uppercase font-bold">Healed</span>
                             <button
                                @click="openEditModal(injury)"
                                class="text-text-muted hover:text-text-main"
                            >
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                        </div>
                    </GlassCard>
                </div>
            </section>
        </div>

        <Modal :show="showModal" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-text-main mb-6">
                    {{ editingInjury ? 'Edit Injury' : 'Log New Injury' }}
                </h2>
                <InjuryForm
                    :injury="editingInjury"
                    @success="closeModal"
                    @close="closeModal"
                />
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
