<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    journals: Array,
})

const showAddForm = ref(false)
const editingJournal = ref(null)

const form = useForm({
    date: new Date().toISOString().substr(0, 10),
    content: '',
    mood_score: null,
    sleep_quality: null,
    stress_level: null,
})

const moods = [
    { value: 5, label: '🤩 Excellent' },
    { value: 4, label: '🙂 Bon' },
    { value: 3, label: '😐 Moyen' },
    { value: 2, label: '🙁 Mauvais' },
    { value: 1, label: '😫 Terrible' },
]

const openAddForm = () => {
    form.reset()
    form.date = new Date().toISOString().substr(0, 10)
    editingJournal.value = null
    showAddForm.value = true
}

const editJournal = (journal) => {
    form.date = journal.date
    form.content = journal.content
    form.mood_score = journal.mood_score
    form.sleep_quality = journal.sleep_quality
    form.stress_level = journal.stress_level
    editingJournal.value = journal
    showAddForm.value = true
}

const submit = () => {
    form.post(route('daily-journals.store'), {
        onSuccess: () => {
            showAddForm.value = false
            form.reset()
            editingJournal.value = null
        },
    })
}

const deleteJournal = (id) => {
    if (confirm('Supprimer cette entrée ?')) {
        useForm({}).delete(route('daily-journals.destroy', id))
    }
}

// Group journals by month
const journalsByMonth = computed(() => {
    const groups = {}
    props.journals.forEach((journal) => {
        const date = new Date(journal.date + 'T00:00:00')
        const key = date.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
        if (!groups[key]) {
            groups[key] = []
        }
        groups[key].push(journal)
    })
    return groups
})

const formatDate = (dateStr) => {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    })
}
</script>

<template>
    <Head title="Journal" />

    <AuthenticatedLayout page-title="Journal">
        <template #header-actions>
            <GlassButton size="sm" @click="openAddForm">
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Journal Quotidien</h2>
                <GlassButton @click="openAddForm">
                    <svg
                        class="mr-2 h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Add/Edit Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-white">
                        {{ editingJournal ? 'Modifier l\'entrée' : 'Nouvelle entrée' }}
                    </h3>
                    <button @click="showAddForm = false" class="text-white/50 hover:text-white">✕</button>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.date"
                        type="date"
                        label="Date"
                        :error="form.errors.date"
                        required
                    />

                    <div>
                        <label class="mb-1 block text-sm font-medium text-white/70">Humeur</label>
                        <div class="flex gap-2">
                            <button
                                v-for="mood in moods"
                                :key="mood.value"
                                type="button"
                                @click="form.mood_score = mood.value"
                                :class="[
                                    'flex-1 rounded-lg border border-white/10 p-2 text-center text-sm transition',
                                    form.mood_score === mood.value
                                        ? 'bg-accent-primary text-white border-transparent'
                                        : 'bg-white/5 text-white/60 hover:bg-white/10'
                                ]"
                            >
                                <div class="text-xl">{{ mood.label.split(' ')[0] }}</div>
                            </button>
                        </div>
                        <div v-if="form.errors.mood_score" class="mt-1 text-xs text-red-400">
                            {{ form.errors.mood_score }}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.sleep_quality"
                            type="number"
                            min="1"
                            max="5"
                            label="Sommeil (1-5)"
                            placeholder="Qualité"
                            :error="form.errors.sleep_quality"
                        />
                        <GlassInput
                            v-model="form.stress_level"
                            type="number"
                            min="1"
                            max="10"
                            label="Stress (1-10)"
                            placeholder="Niveau"
                            :error="form.errors.stress_level"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-white/70">Notes</label>
                        <textarea
                            v-model="form.content"
                            rows="4"
                            class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-white placeholder-white/30 backdrop-blur-md focus:border-accent-primary focus:outline-none focus:ring-1 focus:ring-accent-primary"
                            placeholder="Comment s'est passée votre journée ? Entraînement, repas, sensations..."
                        ></textarea>
                        <div v-if="form.errors.content" class="mt-1 text-xs text-red-400">
                            {{ form.errors.content }}
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <GlassButton type="button" variant="secondary" @click="showAddForm = false">
                            Annuler
                        </GlassButton>
                        <GlassButton type="submit" variant="primary" :loading="form.processing">
                            Enregistrer
                        </GlassButton>
                    </div>
                </form>
            </GlassCard>

            <!-- Journal Entries List -->
            <div v-if="journals.length === 0 && !showAddForm" class="py-12 text-center">
                <div class="mb-4 text-5xl">📓</div>
                <h3 class="text-lg font-medium text-white">Votre journal est vide</h3>
                <p class="text-white/50">Commencez par ajouter une note pour aujourd'hui.</p>
                <GlassButton class="mt-4" @click="openAddForm">Commencer</GlassButton>
            </div>

            <div v-else class="space-y-8">
                <div v-for="(group, month) in journalsByMonth" :key="month">
                    <h3 class="mb-4 text-lg font-medium capitalize text-white/80 sticky top-0 bg-dark-bg/80 backdrop-blur-sm p-2 z-10 rounded-lg">{{ month }}</h3>
                    <div class="space-y-4">
                        <GlassCard
                            v-for="journal in group"
                            :key="journal.id"
                            class="group relative overflow-hidden transition hover:bg-white/10"
                            padding="p-0"
                        >
                            <div class="flex flex-col sm:flex-row">
                                <!-- Date Column -->
                                <div class="flex w-full shrink-0 flex-row items-center justify-between bg-white/5 p-4 sm:w-24 sm:flex-col sm:justify-center sm:border-r sm:border-white/10">
                                    <div class="text-center">
                                        <div class="text-xs uppercase text-white/50">
                                            {{ new Date(journal.date + 'T00:00:00').toLocaleDateString('fr-FR', { weekday: 'short' }) }}
                                        </div>
                                        <div class="text-2xl font-bold text-white">
                                            {{ new Date(journal.date + 'T00:00:00').getDate() }}
                                        </div>
                                    </div>

                                    <!-- Mobile Mood Display -->
                                    <div v-if="journal.mood_score" class="text-2xl sm:hidden">
                                        {{ moods.find(m => m.value === journal.mood_score)?.label.split(' ')[0] }}
                                    </div>
                                </div>

                                <!-- Content Column -->
                                <div class="flex-1 p-4">
                                    <div class="mb-2 flex items-start justify-between">
                                        <div class="flex items-center gap-3">
                                            <div v-if="journal.mood_score" class="hidden text-2xl sm:block" title="Humeur">
                                                {{ moods.find(m => m.value === journal.mood_score)?.label.split(' ')[0] }}
                                            </div>
                                            <div class="flex gap-2">
                                                <span v-if="journal.sleep_quality" class="inline-flex items-center rounded-md bg-indigo-400/10 px-2 py-1 text-xs font-medium text-indigo-400 ring-1 ring-inset ring-indigo-400/30">
                                                    💤 Sommeil: {{ journal.sleep_quality }}/5
                                                </span>
                                                <span v-if="journal.stress_level" class="inline-flex items-center rounded-md bg-orange-400/10 px-2 py-1 text-xs font-medium text-orange-400 ring-1 ring-inset ring-orange-400/30">
                                                    ⚡ Stress: {{ journal.stress_level }}/10
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex gap-1 opacity-0 transition group-hover:opacity-100">
                                            <button @click="editJournal(journal)" class="rounded p-1 text-white/50 hover:bg-white/10 hover:text-white">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button @click="deleteJournal(journal.id)" class="rounded p-1 text-white/50 hover:bg-white/10 hover:text-red-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <p class="whitespace-pre-wrap text-sm text-white/80">{{ journal.content }}</p>
                                </div>
                            </div>
                        </GlassCard>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAB -->
        <button @click="openAddForm" class="glass-fab sm:hidden">
            <svg
                class="h-6 w-6 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
        </button>
    </AuthenticatedLayout>
</template>
