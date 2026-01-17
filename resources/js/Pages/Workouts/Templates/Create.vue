<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    exercises: {
        type: Array,
        default: () => [],
    },
})

const form = useForm({
    name: '',
    description: '',
    exercises: [],
})

const searchQuery = ref('')
const showExerciseList = ref(false)

const filteredExercises = computed(() => {
    if (!searchQuery.value) return props.exercises
    return props.exercises.filter((e) => e.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
})

const addExercise = (exercise) => {
    form.exercises.push({
        id: exercise.id,
        name: exercise.name,
        sets: [{ reps: 10, weight: null, is_warmup: false }],
    })
    showExerciseList.value = false
    searchQuery.value = ''
}

const addSet = (exerciseIndex) => {
    form.exercises[exerciseIndex].sets.push({
        reps: 10,
        weight: null,
        is_warmup: false,
    })
}

const removeSet = (exerciseIndex, setIndex) => {
    form.exercises[exerciseIndex].sets.splice(setIndex, 1)
}

const removeExercise = (index) => {
    form.exercises.splice(index, 1)
}

const submit = () => {
    form.post(route('templates.store'))
}
</script>

<template>
    <Head title="Nouveau Modèle" />

    <AuthenticatedLayout page-title="Nouveau Modèle" show-back back-route="templates.index">
        <form @submit.prevent="submit" class="space-y-6">
            <GlassCard class="animate-slide-up">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-text-muted">Nom du modèle</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white/50 text-text-main focus:ring-2 focus:ring-accent-primary"
                            placeholder="ex: Full Body Lundi"
                        />
                        <div v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-text-muted">Description (optionnel)</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            class="mt-1 w-full rounded-xl border border-slate-200 bg-white/50 text-text-main focus:ring-2 focus:ring-accent-primary"
                            placeholder="Détails de la séance..."
                        ></textarea>
                    </div>
                </div>
            </GlassCard>

            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="mb-3 font-semibold text-text-main">Exercices</h3>

                <div class="space-y-4">
                    <div v-for="(exercise, exIndex) in form.exercises" :key="exIndex">
                        <GlassCard class="relative">
                            <button
                                @click="removeExercise(exIndex)"
                                type="button"
                                class="absolute right-4 top-4 text-text-muted/30 hover:text-red-400"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>

                            <div class="mb-4">
                                <h4 class="text-lg font-bold text-text-main">{{ exercise.name }}</h4>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(set, setIndex) in exercise.sets"
                                    :key="setIndex"
                                    class="flex items-center gap-2"
                                >
                                    <div
                                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-text-muted"
                                    >
                                        {{ setIndex + 1 }}
                                    </div>
                                    <input
                                        v-model="set.reps"
                                        type="number"
                                        class="w-20 rounded-lg border border-slate-200 bg-white/50 text-center text-sm text-text-main placeholder:text-text-muted/40"
                                        placeholder="reps"
                                    />
                                    <input
                                        v-model="set.weight"
                                        type="number"
                                        step="0.5"
                                        class="w-20 rounded-lg border border-slate-200 bg-white/50 text-center text-sm text-text-main placeholder:text-text-muted/40"
                                        placeholder="kg"
                                    />
                                    <button
                                        @click="set.is_warmup = !set.is_warmup"
                                        type="button"
                                        class="rounded-lg px-2 py-1 text-[10px] font-bold transition"
                                        :class="
                                            set.is_warmup
                                                ? 'bg-orange-500/20 text-orange-400'
                                                : 'bg-slate-100 text-text-muted/50'
                                        "
                                    >
                                        W
                                    </button>
                                    <button
                                        @click="removeSet(exIndex, setIndex)"
                                        type="button"
                                        class="ml-auto p-1 text-text-muted/20 hover:text-red-400"
                                    >
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <button
                                    @click="addSet(exIndex)"
                                    type="button"
                                    class="text-xs text-accent-primary hover:underline"
                                >
                                    + Ajouter une série
                                </button>
                            </div>
                        </GlassCard>
                    </div>

                    <div class="relative">
                        <GlassButton @click="showExerciseList = !showExerciseList" type="button" class="w-full">
                            + Ajouter un exercice
                        </GlassButton>

                        <div
                            v-if="showExerciseList"
                            class="absolute bottom-full left-0 right-0 z-50 mb-2 max-h-60 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"
                        >
                            <input
                                v-model="searchQuery"
                                type="text"
                                class="sticky top-0 mb-2 w-full rounded-xl border border-slate-200 bg-slate-50 text-sm text-text-main"
                                placeholder="Rechercher..."
                            />
                            <div class="space-y-1">
                                <button
                                    v-for="ex in filteredExercises"
                                    :key="ex.id"
                                    type="button"
                                    @click="addExercise(ex)"
                                    class="w-full rounded-xl p-3 text-left transition hover:bg-slate-50"
                                >
                                    <div class="text-sm font-medium text-text-main">{{ ex.name }}</div>
                                    <div class="text-[10px] text-text-muted">{{ ex.category }}</div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="animate-slide-up pt-6" style="animation-delay: 0.2s">
                <GlassButton variant="primary" size="lg" class="w-full" :loading="form.processing" type="submit">
                    Enregistrer le modèle
                </GlassButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
