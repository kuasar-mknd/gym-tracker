<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    exercises: Array,
    categories: Array,
    types: Array,
})

const showAddForm = ref(false)
const editingExercise = ref(null)

const form = useForm({
    name: '',
    type: 'strength',
    category: '',
})

const editForm = useForm({
    name: '',
    type: '',
    category: '',
})

const submit = () => {
    form.post(route('exercises.store'), {
        onSuccess: () => {
            form.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (exercise) => {
    editingExercise.value = exercise.id
    editForm.name = exercise.name
    editForm.type = exercise.type
    editForm.category = exercise.category || ''
}

const cancelEdit = () => {
    editingExercise.value = null
    editForm.reset()
}

const updateExercise = (exercise) => {
    editForm.put(route('exercises.update', exercise.id), {
        onSuccess: () => {
            editingExercise.value = null
        },
    })
}

const deleteExercise = (id) => {
    if (confirm('Supprimer cet exercice ?')) {
        router.delete(route('exercises.destroy', id))
    }
}

const groupedExercises = computed(() => {
    const groups = {}
    props.exercises.forEach((exercise) => {
        const cat = exercise.category || 'Autres'
        if (!groups[cat]) {
            groups[cat] = []
        }
        groups[cat].push(exercise)
    })
    return groups
})

const typeIcon = (type) => {
    switch (type) {
        case 'strength':
            return '💪'
        case 'cardio':
            return '🏃'
        case 'timed':
            return '⏱️'
        default:
            return '🏋️'
    }
}

const typeLabel = (type) => {
    const found = props.types.find((t) => t.value === type)
    return found ? found.label : type
}
</script>

<template>
    <Head title="Exercices" />

    <AuthenticatedLayout page-title="Mes Exercices">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm">
                <svg
                    class="h-4 w-4"
                    :class="{ 'mr-2': !showAddForm }"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path
                        v-if="!showAddForm"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M12 4v16m8-8H4"
                    />
                    <path
                        v-else
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12"
                    />
                </svg>
                <span v-if="!showAddForm">Ajouter</span>
            </GlassButton>
        </template>

        <div class="space-y-6">
            <!-- Stats -->
            <div class="grid animate-slide-up grid-cols-3 gap-3">
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-white">{{ exercises.length }}</div>
                        <div class="mt-1 text-xs text-white/60">Total</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-white">
                            {{ exercises.filter((e) => e.type === 'strength').length }}
                        </div>
                        <div class="mt-1 text-xs text-white/60">Force</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-white">
                            {{ exercises.filter((e) => e.type === 'cardio').length }}
                        </div>
                        <div class="mt-1 text-xs text-white/60">Cardio</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Add Form -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="mb-4 font-semibold text-white">Nouvel exercice</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom de l'exercice"
                        placeholder="Ex: Développé couché"
                        :error="form.errors.name"
                    />
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-white/70">Type</label>
                            <select v-model="form.type" class="glass-input w-full">
                                <option v-for="t in types" :key="t.value" :value="t.value">
                                    {{ t.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.type" class="mt-1.5 text-sm text-red-400">
                                {{ form.errors.type }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-white/70">Catégorie</label>
                            <select v-model="form.category" class="glass-input w-full">
                                <option value="">— Aucune —</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">
                                    {{ cat }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                        Créer l'exercice
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- Error display -->
            <GlassCard v-if="$page.props.errors?.exercise" class="border-red-500/50 bg-red-500/10">
                <p class="text-center text-red-400">{{ $page.props.errors.exercise }}</p>
            </GlassCard>

            <!-- Exercises List by Category -->
            <div v-if="exercises.length === 0" class="animate-slide-up">
                <GlassCard>
                    <div class="py-8 text-center">
                        <div class="mb-2 text-4xl">🏋️</div>
                        <p class="text-white/60">Aucun exercice pour l'instant</p>
                        <GlassButton variant="primary" class="mt-4" size="sm" @click="showAddForm = true">
                            Créer le premier exercice
                        </GlassButton>
                    </div>
                </GlassCard>
            </div>

            <div v-else class="space-y-6">
                <div
                    v-for="(exercisesInCat, category) in groupedExercises"
                    :key="category"
                    class="animate-slide-up"
                    style="animation-delay: 0.1s"
                >
                    <h3 class="mb-3 font-semibold text-white">{{ category }}</h3>
                    <div class="space-y-2">
                        <GlassCard v-for="exercise in exercisesInCat" :key="exercise.id" padding="p-4" class="group">
                            <!-- View Mode -->
                            <div v-if="editingExercise !== exercise.id" class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl">{{ typeIcon(exercise.type) }}</span>
                                    <div>
                                        <div class="font-semibold text-white">{{ exercise.name }}</div>
                                        <div class="text-xs text-white/50">{{ typeLabel(exercise.type) }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 opacity-0 transition group-hover:opacity-100">
                                    <button
                                        @click="startEdit(exercise)"
                                        class="rounded-lg p-2 text-white/30 transition hover:text-white"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                            />
                                        </svg>
                                    </button>
                                    <button
                                        @click="deleteExercise(exercise.id)"
                                        class="rounded-lg p-2 text-white/30 transition hover:text-red-400"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

                            <!-- Edit Mode -->
                            <form v-else @submit.prevent="updateExercise(exercise)" class="space-y-3">
                                <GlassInput
                                    v-model="editForm.name"
                                    placeholder="Nom de l'exercice"
                                    :error="editForm.errors.name"
                                    size="sm"
                                />
                                <div class="grid grid-cols-2 gap-3">
                                    <select v-model="editForm.type" class="glass-input text-sm">
                                        <option v-for="t in types" :key="t.value" :value="t.value">
                                            {{ t.label }}
                                        </option>
                                    </select>
                                    <select v-model="editForm.category" class="glass-input text-sm">
                                        <option value="">— Aucune —</option>
                                        <option v-for="cat in categories" :key="cat" :value="cat">
                                            {{ cat }}
                                        </option>
                                    </select>
                                </div>
                                <div class="flex gap-2">
                                    <GlassButton
                                        type="submit"
                                        variant="primary"
                                        size="sm"
                                        :loading="editForm.processing"
                                    >
                                        Sauvegarder
                                    </GlassButton>
                                    <GlassButton type="button" variant="ghost" size="sm" @click="cancelEdit">
                                        Annuler
                                    </GlassButton>
                                </div>
                            </form>
                        </GlassCard>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
