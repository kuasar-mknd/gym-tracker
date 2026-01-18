<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed, defineAsyncComponent } from 'vue'

const JournalChart = defineAsyncComponent(() => import('@/Components/Stats/JournalChart.vue'))

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
    energy_level: null,
    motivation_level: null,
    nutrition_score: null,
    training_intensity: null,
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
    form.energy_level = journal.energy_level
    form.motivation_level = journal.motivation_level
    form.nutrition_score = journal.nutrition_score
    form.training_intensity = journal.training_intensity
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
        useForm({}).delete(route('daily-journals.destroy', { daily_journal: id }))
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
                <h2 class="text-xl font-semibold text-text-main">Journal</h2>
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
                    <h3 class="font-display text-lg font-black uppercase italic text-text-main">Tendances</h3>
                    <p class="text-xs font-semibold text-text-muted">Évolution de vos métriques</p>
                </div>
                <JournalChart :data="journals" />
            </GlassCard>

            <!-- Add/Edit Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-text-main">
                        {{ editingJournal ? "Modifier l'entrée" : 'Nouvelle entrée' }}
                    </h3>
                    <button @click="showAddForm = false" class="text-text-muted hover:text-text-main">✕</button>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput v-model="form.date" type="date" label="Date" :error="form.errors.date" required />

                    <div>
                        <label class="mb-1 block text-sm font-medium text-text-muted">Humeur</label>
                        <div class="flex gap-2">
                            <button
                                v-for="mood in moods"
                                :key="mood.value"
                                type="button"
                                @click="form.mood_score = mood.value"
                                :class="[
                                    'flex-1 rounded-lg border border-slate-200 p-2 text-center text-sm transition',
                                    form.mood_score === mood.value
                                        ? 'border-transparent bg-accent-primary text-white'
                                        : 'bg-white/50 text-text-muted hover:bg-slate-50',
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

                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.energy_level"
                            type="number"
                            min="1"
                            max="10"
                            label="Énergie (1-10)"
                            placeholder="Niveau"
                            :error="form.errors.energy_level"
                        />
                        <GlassInput
                            v-model="form.motivation_level"
                            type="number"
                            min="1"
                            max="10"
                            label="Motivation (1-10)"
                            placeholder="Niveau"
                            :error="form.errors.motivation_level"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <GlassInput
                            v-model="form.nutrition_score"
                            type="number"
                            min="1"
                            max="5"
                            label="Diète (1-5)"
                            placeholder="Qualité"
                            :error="form.errors.nutrition_score"
                        />
                        <GlassInput
                            v-model="form.training_intensity"
                            type="number"
                            min="1"
                            max="10"
                            label="Intensité (1-10)"
                            placeholder="Effort"
                            :error="form.errors.training_intensity"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-text-muted">Notes</label>
                        <textarea
                            v-model="form.content"
                            rows="4"
                            class="w-full rounded-xl border border-slate-200 bg-white/50 px-4 py-2 text-text-main placeholder-text-muted/30 backdrop-blur-md focus:border-accent-primary focus:outline-none focus:ring-1 focus:ring-accent-primary"
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
                <h3 class="text-lg font-medium text-text-main">Votre journal est vide</h3>
                <p class="text-text-muted">Commencez par ajouter une note pour aujourd'hui.</p>
                <GlassButton class="mt-4" @click="openAddForm">Commencer</GlassButton>
            </div>

            <div v-else class="space-y-8">
                <div v-for="(group, month) in journalsByMonth" :key="month">
                    <h3
                        class="sticky top-0 z-10 mb-4 rounded-lg bg-pearl-white/80 p-2 text-lg font-medium capitalize text-text-main backdrop-blur-sm"
                    >
                        {{ month }}
                    </h3>
                    <div class="space-y-4">
                        <GlassCard
                            v-for="journal in group"
                            :key="journal.id"
                            class="group relative overflow-hidden transition hover:bg-white/10"
                            padding="p-0"
                        >
                            <div class="flex flex-col sm:flex-row">
                                <!-- Date Column -->
                                <div
                                    class="flex w-full shrink-0 flex-row items-center justify-between bg-slate-50 p-4 sm:w-24 sm:flex-col sm:justify-center sm:border-r sm:border-slate-100"
                                >
                                    <div class="text-center">
                                        <div class="text-xs uppercase text-text-muted">
                                            {{
                                                new Date(journal.date + 'T00:00:00').toLocaleDateString('fr-FR', {
                                                    weekday: 'short',
                                                })
                                            }}
                                        </div>
                                        <div class="text-2xl font-bold text-text-main">
                                            {{ new Date(journal.date + 'T00:00:00').getDate() }}
                                        </div>
                                    </div>

                                    <!-- Mobile Mood Display -->
                                    <div v-if="journal.mood_score" class="text-2xl sm:hidden">
                                        {{ moods.find((m) => m.value === journal.mood_score)?.label.split(' ')[0] }}
                                    </div>
                                </div>

                                <!-- Content Column -->
                                <div class="flex-1 p-4">
                                    <div class="mb-2 flex items-start justify-between">
                                        <div class="flex items-center gap-3">
                                            <div
                                                v-if="journal.mood_score"
                                                class="hidden text-2xl sm:block"
                                                title="Humeur"
                                            >
                                                {{
                                                    moods
                                                        .find((m) => m.value === journal.mood_score)
                                                        ?.label.split(' ')[0]
                                                }}
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <span
                                                    v-if="journal.sleep_quality"
                                                    class="inline-flex items-center rounded-md bg-indigo-400/10 px-2 py-1 text-[10px] font-medium text-indigo-400 ring-1 ring-inset ring-indigo-400/30"
                                                >
                                                    💤 {{ journal.sleep_quality }}/5
                                                </span>
                                                <span
                                                    v-if="journal.stress_level"
                                                    class="inline-flex items-center rounded-md bg-orange-400/10 px-2 py-1 text-[10px] font-medium text-orange-400 ring-1 ring-inset ring-orange-400/30"
                                                >
                                                    ⚡ Stress: {{ journal.stress_level }}/10
                                                </span>
                                                <span
                                                    v-if="journal.energy_level"
                                                    class="inline-flex items-center rounded-md bg-yellow-400/10 px-2 py-1 text-[10px] font-medium text-yellow-400 ring-1 ring-inset ring-yellow-400/30"
                                                >
                                                    🔋 Énergie: {{ journal.energy_level }}/10
                                                </span>
                                                <span
                                                    v-if="journal.motivation_level"
                                                    class="inline-flex items-center rounded-md bg-pink-400/10 px-2 py-1 text-[10px] font-medium text-pink-400 ring-1 ring-inset ring-pink-400/30"
                                                >
                                                    🔥 Motivation: {{ journal.motivation_level }}/10
                                                </span>
                                                <span
                                                    v-if="journal.nutrition_score"
                                                    class="inline-flex items-center rounded-md bg-emerald-400/10 px-2 py-1 text-[10px] font-medium text-emerald-400 ring-1 ring-inset ring-emerald-400/30"
                                                >
                                                    🥗 Diète: {{ journal.nutrition_score }}/5
                                                </span>
                                                <span
                                                    v-if="journal.training_intensity"
                                                    class="inline-flex items-center rounded-md bg-red-400/10 px-2 py-1 text-[10px] font-medium text-red-600 ring-1 ring-inset ring-red-400/30"
                                                >
                                                    🏋️ Intensité: {{ journal.training_intensity }}/10
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex gap-1 opacity-0 transition group-hover:opacity-100">
                                            <button
                                                @click="editJournal(journal)"
                                                class="rounded p-1 text-text-muted/50 hover:bg-slate-100/50 hover:text-text-main"
                                            >
                                                <svg
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                    />
                                                </svg>
                                            </button>
                                            <button
                                                @click="deleteJournal(journal.id)"
                                                class="rounded p-1 text-text-muted/50 hover:bg-slate-100/50 hover:text-red-400"
                                            >
                                                <svg
                                                    class="h-4 w-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <p class="whitespace-pre-wrap text-sm text-text-main">{{ journal.content }}</p>
                                </div>
                            </div>
                        </GlassCard>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
