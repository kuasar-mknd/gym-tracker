<!--
  Journal/Index.vue - Daily Journal & Wellness Tracker

  This page allows users to:
  - Log daily wellness metrics (mood, sleep, stress, energy, etc.).
  - Write free-text journal entries.
  - View historical data grouped by month.
  - Visualize trends via the JournalChart component.
  - Add, edit, and delete journal entries.
-->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import JournalForm from '@/Components/Journal/JournalForm.vue'
import JournalList from '@/Components/Journal/JournalList.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed, defineAsyncComponent } from 'vue'

const JournalChart = defineAsyncComponent(() => import('@/Components/Stats/JournalChart.vue'))

/**
 * Component Props
 * @property {Array} journals - List of journal entries for the user.
 */
const props = defineProps({
    journals: Array,
})

// --- State Management ---

/** Controls the visibility of the Add/Edit form modal/card. */
const showAddForm = ref(false)

/** Holds the journal entry currently being edited (null if creating new). */
const editingJournal = ref(null)

/**
 * Inertia form handling for journal entries.
 * Includes fields for date, content (text), and various wellness scores.
 */
const form = useForm({
    date: new Date().toISOString().substr(0, 10),
    content: '',
    mood_score: null,
    sleep_quality: null,
    stress_level: null,
    energy_level: null,
    motivation_level: null,
    nutrition_score: null,
    training_intensity: null,
})

/** Available mood options with values and labels. */
const moods = [
    { value: 5, label: '🤩 Excellent' },
    { value: 4, label: '🙂 Bon' },
    { value: 3, label: '😐 Moyen' },
    { value: 2, label: '🙁 Mauvais' },
    { value: 1, label: '😫 Terrible' },
]

/**
 * Opens the form to create a new journal entry.
 * Resets the form to default values.
 */
const openAddForm = () => {
    form.reset()
    form.date = new Date().toISOString().substr(0, 10)
    editingJournal.value = null
    showAddForm.value = true
}

/**
 * Opens the form to edit an existing journal entry.
 * Populates the form with the entry's data.
 *
 * @param {Object} journal - The journal entry to edit.
 */
const editJournal = (journal) => {
    form.date = journal.date
    form.content = journal.content
    form.mood_score = journal.mood_score
    form.sleep_quality = journal.sleep_quality
    form.stress_level = journal.stress_level
    form.energy_level = journal.energy_level
    form.motivation_level = journal.motivation_level
    form.nutrition_score = journal.nutrition_score
    form.training_intensity = journal.training_intensity
    editingJournal.value = journal
    showAddForm.value = true
}

/**
 * Submits the form to create or update a journal entry.
 * The backend handles upsert based on the date.
 */
const submit = () => {
    form.post(route('daily-journals.store'), {
        onSuccess: () => {
            showAddForm.value = false
            form.reset()
            editingJournal.value = null
        },
    })
}

/**
 * Deletes a journal entry after confirmation.
 *
 * @param {Number} id - The ID of the journal entry to delete.
 */
const deleteJournal = (id) => {
    if (confirm('Supprimer cette entrée ?')) {
        useForm({}).delete(route('daily-journals.destroy', { daily_journal: id }))
    }
}

/**
 * Computed property that groups journal entries by month for display.
 * Returns an object where keys are "Month Year" strings and values are arrays of journals.
 */
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
                <h2 class="text-text-main text-xl font-semibold">Journal</h2>
                <GlassButton @click="openAddForm" aria-label="Nouvelle séance">
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
            <!-- Stats Chart -->
            <GlassCard v-if="journals.length > 1" class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Tendances</h3>
                    <p class="text-text-muted text-xs font-semibold">Évolution de vos métriques</p>
                </div>
                <JournalChart :data="journals" />
            </GlassCard>

            <JournalForm
                v-if="showAddForm"
                :form="form"
                :moods="moods"
                :editing-journal="editingJournal"
                @close="showAddForm = false"
                @submit="submit"
            />

            <div v-if="journals.length === 0 && !showAddForm" class="py-12 text-center">
                <div class="mb-4 text-5xl">📓</div>
                <h3 class="text-text-main text-lg font-medium">Votre journal est vide</h3>
                <p class="text-text-muted">Commencez par ajouter une note pour aujourd'hui.</p>
                <GlassButton class="mt-4" @click="openAddForm">Commencer</GlassButton>
            </div>

            <JournalList
                v-else
                :journals-by-month="journalsByMonth"
                :moods="moods"
                @edit="editJournal"
                @delete="deleteJournal"
            />
        </div>
    </AuthenticatedLayout>
</template>
