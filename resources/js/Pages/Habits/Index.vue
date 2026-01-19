<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    habits: Array,
    weekDates: Array,
})

const showAddForm = ref(false)
const editingHabit = ref(null)

const form = useForm({
    name: '',
    description: '',
    color: 'bg-slate-500',
    icon: 'check_circle',
    goal_times_per_week: 7,
})

const icons = [
    'check_circle',
    'fitness_center',
    'water_drop',
    'bedtime',
    'restaurant',
    'self_improvement',
    'local_fire_department',
    'bolt',
    'directions_run',
    'monitor_heart',
    'spa',
    'medication',
    'local_cafe',
    'no_drinks',
    'savings',
    'book',
]
const colors = [
    'bg-slate-500',
    'bg-red-500',
    'bg-orange-500',
    'bg-amber-500',
    'bg-green-500',
    'bg-emerald-500',
    'bg-teal-500',
    'bg-cyan-500',
    'bg-sky-500',
    'bg-blue-500',
    'bg-indigo-500',
    'bg-violet-500',
    'bg-purple-500',
    'bg-fuchsia-500',
    'bg-pink-500',
    'bg-rose-500',
]

const openAddForm = () => {
    form.reset()
    editingHabit.value = null
    showAddForm.value = true
}

const editHabit = (habit) => {
    editingHabit.value = habit
    form.name = habit.name
    form.description = habit.description || ''
    form.color = habit.color
    form.icon = habit.icon
    form.goal_times_per_week = habit.goal_times_per_week
    showAddForm.value = true
}

const submit = () => {
    if (editingHabit.value) {
        form.put(route('habits.update', editingHabit.value.id), {
            onSuccess: () => (showAddForm.value = false),
        })
    } else {
        form.post(route('habits.store'), {
            onSuccess: () => {
                showAddForm.value = false
                form.reset()
            },
        })
    }
}

const deleteHabit = (habit) => {
    if (confirm('Voulez-vous vraiment supprimer cette habitude ?')) {
        router.delete(route('habits.destroy', habit.id))
    }
}

const toggleHabit = (habit, date) => {
    router.post(
        route('habits.toggle', habit.id),
        {
            date: date,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['habits'], // Optimization: only reload habits prop
        },
    )
}

const isCompleted = (habit, date) => {
    return habit.logs.some((log) => log.date === date)
}

const getCompletionCount = (habit) => {
    return habit.logs.length
}

const getProgressPercent = (habit) => {
    const count = getCompletionCount(habit)
    const goal = habit.goal_times_per_week
    return Math.min((count / goal) * 100, 100)
}
</script>

<template>
    <Head title="Habitudes" />

    <AuthenticatedLayout page-title="Habitudes">
        <template #header-actions>
            <GlassButton size="sm" @click="openAddForm">
                <span class="material-symbols-outlined text-sm">add</span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-text-main">Habitudes</h2>
                <GlassButton @click="openAddForm">
                    <span class="material-symbols-outlined mr-2 text-sm">add</span>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Weekly Calendar Header -->
            <GlassCard class="overflow-hidden p-0">
                <div class="grid grid-cols-[1fr_repeat(7,minmax(32px,1fr))] sm:grid-cols-[200px_repeat(7,1fr)]">
                    <div class="p-4 font-bold text-text-main">Habitude</div>
                    <div
                        v-for="day in weekDates"
                        :key="day.date"
                        class="flex flex-col items-center justify-center border-l border-slate-100 p-2 text-center"
                        :class="{ 'bg-accent-primary/5': day.is_today }"
                    >
                        <div class="text-[10px] uppercase text-text-muted">{{ day.day_short || day.day }}</div>
                        <div class="text-sm font-bold" :class="day.is_today ? 'text-accent-primary' : 'text-text-main'">
                            {{ day.day_num }}
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- Habits List -->
            <div v-if="habits.length === 0" class="py-12 text-center">
                <div class="mb-4 text-5xl">✅</div>
                <h3 class="text-lg font-medium text-text-main">Aucune habitude</h3>
                <p class="text-text-muted">Commencez par créer une habitude à suivre.</p>
                <GlassButton class="mt-4" @click="openAddForm">Créer ma première habitude</GlassButton>
            </div>

            <div v-else class="space-y-3">
                <GlassCard
                    v-for="habit in habits"
                    :key="habit.id"
                    class="group overflow-hidden p-0 transition hover:bg-white/10"
                >
                    <div class="grid grid-cols-[1fr_repeat(7,minmax(32px,1fr))] sm:grid-cols-[200px_repeat(7,1fr)]">
                        <!-- Habit Info -->
                        <div class="relative flex flex-col justify-center p-4">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white"
                                    :class="habit.color"
                                >
                                    <span class="material-symbols-outlined">{{ habit.icon }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate font-bold text-text-main">{{ habit.name }}</h3>
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                class="h-full rounded-full transition-all duration-500"
                                                :class="habit.color"
                                                :style="{ width: getProgressPercent(habit) + '%' }"
                                            ></div>
                                        </div>
                                        <span class="text-[10px] text-text-muted"
                                            >{{ getCompletionCount(habit) }}/{{ habit.goal_times_per_week }}</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Actions (Absolute) -->
                            <div class="absolute right-2 top-2 flex opacity-0 transition group-hover:opacity-100">
                                <button @click="editHabit(habit)" class="p-1 text-text-muted hover:text-text-main">
                                    <span class="material-symbols-outlined text-sm">edit</span>
                                </button>
                                <button @click="deleteHabit(habit)" class="p-1 text-text-muted hover:text-red-500">
                                    <span class="material-symbols-outlined text-sm">delete</span>
                                </button>
                            </div>
                        </div>

                        <!-- Checkboxes -->
                        <div
                            v-for="day in weekDates"
                            :key="day.date"
                            class="flex items-center justify-center border-l border-slate-100 p-2"
                            :class="{ 'bg-accent-primary/5': day.is_today }"
                        >
                            <button
                                @click="toggleHabit(habit, day.date)"
                                class="flex h-8 w-8 items-center justify-center rounded-full transition-all active:scale-95"
                                :class="[
                                    isCompleted(habit, day.date)
                                        ? `${habit.color} text-white shadow-md`
                                        : 'bg-slate-100 text-slate-300 hover:bg-slate-200',
                                ]"
                            >
                                <span class="material-symbols-outlined text-lg">check</span>
                            </button>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <div v-if="showAddForm" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showAddForm = false"></div>
            <GlassCard class="relative w-full max-w-lg animate-scale-in shadow-2xl" variant="solid">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-xl font-bold text-text-main">
                        {{ editingHabit ? 'Modifier' : 'Nouvelle Habitude' }}
                    </h3>
                    <button @click="showAddForm = false" class="text-text-muted hover:text-text-main">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <GlassInput
                        v-model="form.name"
                        label="Nom"
                        placeholder="Ex: Boire 2L d'eau"
                        :error="form.errors.name"
                        required
                    />

                    <GlassInput
                        v-model="form.description"
                        label="Description (optionnel)"
                        placeholder="Détails..."
                        :error="form.errors.description"
                    />

                    <div>
                        <label class="mb-1 block text-sm font-medium text-text-muted">Couleur</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in colors"
                                :key="color"
                                type="button"
                                @click="form.color = color"
                                class="h-8 w-8 rounded-full border-2 transition"
                                :class="[
                                    color,
                                    form.color === color ? 'scale-110 border-text-main' : 'border-transparent',
                                ]"
                            ></button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-text-muted">Icône</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="icon in icons"
                                :key="icon"
                                type="button"
                                @click="form.icon = icon"
                                class="flex h-10 w-10 items-center justify-center rounded-lg border-2 transition hover:bg-slate-100"
                                :class="[
                                    form.icon === icon
                                        ? 'border-accent-primary bg-accent-primary/10 text-accent-primary'
                                        : 'border-transparent text-text-muted',
                                ]"
                            >
                                <span class="material-symbols-outlined">{{ icon }}</span>
                            </button>
                        </div>
                    </div>

                    <GlassInput
                        v-model="form.goal_times_per_week"
                        type="number"
                        min="1"
                        max="7"
                        label="Objectif (fois par semaine)"
                        :error="form.errors.goal_times_per_week"
                    />

                    <div class="flex justify-end gap-3 pt-4">
                        <GlassButton type="button" variant="ghost" @click="showAddForm = false">Annuler</GlassButton>
                        <GlassButton type="submit" variant="primary" :loading="form.processing"
                            >Enregistrer</GlassButton
                        >
                    </div>
                </form>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>
