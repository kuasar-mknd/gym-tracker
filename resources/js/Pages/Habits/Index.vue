<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import FormulaireDHabitude from '@/Components/Habits/FormulaireDHabitude.vue'
import { Head, router, Deferred } from '@inertiajs/vue3'
import { ref, defineAsyncComponent } from 'vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

const HabitHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/HabitHistoryChart.vue'))
const HabitConsistencyChart = defineAsyncComponent(() => import('@/Components/Stats/HabitConsistencyChart.vue'))

/**
 * Les habitudes de la semaine : une ligne par habitude, une case par jour, et
 * deux graphiques différés sur trente jours. Le formulaire vit dans
 * `FormulaireDHabitude`.
 */
defineProps({
    /**
     * List of user's active habits with their logs for the current week.
     * @type {Array<{id: number, name: string, description: string|null, color: string, icon: string, goal_times_per_week: number, logs: Array}>}
     */
    habits: Array,
    /**
     * Array of date objects representing the current week.
     * @type {Array<{date: string, day: string, day_name: string, day_short: string, day_num: number, is_today: boolean}>}
     */
    weekDates: Array,
    /**
     * Consolidated statistical data (Deferred Loading).
     * @type {{consistencyData: Array, history: Array}}
     */
    stats: {
        type: Object,
        default: () => ({ consistencyData: [], history: [] }),
    },
})

const showAddForm = ref(false)
const editingHabit = ref(null)

const openAddForm = () => {
    editingHabit.value = null
    showAddForm.value = true
}

const editHabit = (habit) => {
    editingHabit.value = habit
    showAddForm.value = true
}

const {
    cible: habitudeASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((habit, termine) => {
    router.delete(route('habits.destroy', habit.id), { onFinish: termine })
})

const toggleHabit = (habit, date) => {
    router.post(
        route('habits.toggle', habit.id),
        {
            date: date,
        },
        {
            preserveScroll: true,
            preserveState: true,
            /*
             * `stats` belongs here with `habits`. It feeds the two thirty-day
             * charts, and leaving it out did not merely delay them — it is a
             * deferred prop, so without it the response carries no
             * `deferredProps` metadata either, nothing re-triggers the load,
             * and Inertia keeps the value from before the tick. Ticking a box
             * moved the row and left both charts describing the previous state.
             */
            only: ['habits', 'stats'],
        },
    )
}

const isCompleted = (habit, date) => {
    return habit.logs.some((log) => log.date === date)
}

const getCompletionCount = (habit) => {
    return habit.logs.length
}

/** Le pourcentage de l'objectif hebdomadaire atteint, plafonné à cent. */
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
            <GlassButton
                variant="primary"
                size="sm"
                @click="openAddForm"
                aria-label="Ajouter une habitude"
                dusk="add-habit"
            >
                <span class="material-symbols-outlined text-sm" aria-hidden="true">add</span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Habitudes</h2>
                <GlassButton variant="primary" @click="openAddForm">
                    <span class="material-symbols-outlined mr-2 text-sm" aria-hidden="true">add</span>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Deferred Analytical Stats -->
            <Deferred data="stats">
                <template #fallback>
                    <div class="space-y-6">
                        <GlassCard class="animate-pulse">
                            <div class="mb-4">
                                <GlassSkeleton width="120px" height="1.5rem" class="mb-2" />
                                <GlassSkeleton width="80px" height="0.75rem" />
                            </div>
                            <GlassSkeleton width="100%" height="200px" class="rounded-xl" />
                        </GlassCard>
                        <GlassCard class="animate-pulse">
                            <div class="mb-4">
                                <GlassSkeleton width="120px" height="1.5rem" class="mb-2" />
                                <GlassSkeleton width="150px" height="0.75rem" />
                            </div>
                            <GlassSkeleton width="100%" height="250px" class="rounded-xl" />
                        </GlassCard>
                    </div>
                </template>

                <div class="space-y-6">
                    <!-- Consistency Chart -->
                    <GlassCard
                        v-if="stats?.consistencyData && stats.consistencyData.some((d) => d.count > 0)"
                        class="animate-slide-up"
                    >
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Régularité</h3>
                            <p class="text-text-muted text-xs font-semibold">30 derniers jours</p>
                        </div>
                        <HabitConsistencyChart :data="stats.consistencyData" />
                    </GlassCard>

                    <!-- Stats Chart -->
                    <GlassCard
                        v-if="stats?.history && stats.history.some((d) => d.count > 0)"
                        class="animate-slide-up"
                        style="animation-delay: 0.1s"
                    >
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Constance</h3>
                            <p class="text-text-muted text-xs font-semibold">Habitudes complétées (30 jours)</p>
                        </div>
                        <HabitHistoryChart :data="stats.history" />
                    </GlassCard>
                </div>
            </Deferred>

            <!-- Weekly Calendar Header -->
            <GlassCard class="overflow-hidden p-0">
                <div class="grid grid-cols-7 sm:grid-cols-[200px_repeat(7,1fr)]">
                    <div class="text-text-main col-span-7 p-4 font-bold sm:col-span-1">Habitude</div>
                    <div
                        v-for="day in weekDates"
                        :key="day.date"
                        class="border-border flex flex-col items-center justify-center border-l p-2 text-center"
                        :class="{ 'bg-accent-primary/5': day.is_today }"
                    >
                        <div class="text-text-muted text-[10px] uppercase">{{ day.day_short || day.day }}</div>
                        <div
                            class="text-sm font-bold"
                            :class="day.is_today ? 'text-accent-primary-deep' : 'text-text-main'"
                        >
                            {{ day.day_num }}
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- Habits List -->
            <GlassEmptyState
                v-if="habits.length === 0"
                icon="✅"
                color="green"
                title="Aucune habitude"
                description="Commencez par créer une habitude à suivre."
                action-label="Créer ma première habitude"
                action-id="empty-state-habit"
                @action="openAddForm"
            />

            <div v-else class="space-y-3">
                <GlassCard
                    v-for="habit in habits"
                    :key="habit.id"
                    class="group hover:bg-surface-card/10 overflow-hidden p-0 transition"
                >
                    <div class="grid grid-cols-7 sm:grid-cols-[200px_repeat(7,1fr)]">
                        <!-- Habit Info -->
                        <div class="relative col-span-7 flex min-w-0 flex-col justify-center p-4 sm:col-span-1">
                            <div class="flex items-center gap-3">
                                <div
                                    class="text-text-on-dark-accent hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl sm:flex"
                                    :class="habit.color"
                                >
                                    <span class="material-symbols-outlined" aria-hidden="true">{{ habit.icon }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-text-main truncate font-bold">{{ habit.name }}</h3>
                                    <div class="flex items-center gap-2">
                                        <div class="bg-surface-sunken h-1.5 w-16 overflow-hidden rounded-full">
                                            <div
                                                class="h-full rounded-full transition-all duration-500"
                                                :class="habit.color"
                                                :style="{ width: getProgressPercent(habit) + '%' }"
                                            ></div>
                                        </div>
                                        <span class="text-text-muted text-[10px]"
                                            >{{ getCompletionCount(habit) }}/{{ habit.goal_times_per_week }}</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Actions (Absolute) -->
                            <!-- Visible by default, hover-revealed only from sm: up. A plain
                                 opacity-0 hides these on touch, where there is no hover at all. -->
                            <div
                                class="mt-2 flex gap-1 opacity-100 transition sm:absolute sm:top-2 sm:right-2 sm:mt-0 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"
                            >
                                <GlassIconButton icon="edit" label="Modifier l'habitude" @click="editHabit(habit)" />
                                <GlassIconButton
                                    icon="delete"
                                    label="Supprimer l'habitude"
                                    ton="danger"
                                    @click="demanderSuppression(habit)"
                                />
                            </div>
                        </div>

                        <!-- Checkboxes -->
                        <div
                            v-for="day in weekDates"
                            :key="day.date"
                            class="border-border flex items-center justify-center border-l p-2"
                            :class="{ 'bg-accent-primary/5': day.is_today }"
                        >
                            <!-- aria-pressed carries the done/not-done state, which colour
                                 alone cannot convey. The before: ring widens the tap target
                                 from 32px to ~44px without changing the visual size, which
                                 would break the 7-column grid. -->
                            <button
                                type="button"
                                @click="toggleHabit(habit, day.date)"
                                :aria-pressed="isCompleted(habit, day.date)"
                                :aria-label="`${habit.name}, ${day.day_short || day.day} ${day.day_num}`"
                                :dusk="`habit-${habit.id}-${day.date}`"
                                class="flex size-11 shrink-0 items-center justify-center rounded-full transition-all active:scale-95"
                                :class="[
                                    isCompleted(habit, day.date)
                                        ? `${habit.color} text-text-on-dark-accent shadow-md`
                                        : 'bg-surface-sunken text-text-muted hover:bg-surface-sunken',
                                ]"
                            >
                                <span class="material-symbols-outlined text-lg" aria-hidden="true">check</span>
                            </button>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>

        <FormulaireDHabitude :show="showAddForm" :habitude="editingHabit" @close="showAddForm = false" />
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cette habitude ?"
            :description="
                habitudeASupprimer ? `« ${habitudeASupprimer.name} » sera effacée, avec tout son historique.` : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
