<script setup>
import { ref, computed, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    year: Number,
    month: Number,
    workouts: Array,
    journals: Array,
})

const currentYear = ref(props.year)
const currentMonth = ref(props.month)
const selectedDate = ref(null)

const monthNames = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
]

const weekDays = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']

const currentMonthName = computed(() => monthNames[currentMonth.value - 1])

const calendarGrid = computed(() => {
    const days = []
    const firstDay = new Date(currentYear.value, currentMonth.value - 1, 1)
    const daysInMonth = new Date(currentYear.value, currentMonth.value, 0).getDate()

    // Adjust for Monday start (0 = Sunday in JS, but we want 0 = Monday)
    let startDayOfWeek = firstDay.getDay()
    startDayOfWeek = startDayOfWeek === 0 ? 6 : startDayOfWeek - 1

    // Add padding for previous month
    for (let i = 0; i < startDayOfWeek; i++) {
        days.push({ day: null, dateStr: null })
    }

    // Add days of current month
    for (let i = 1; i <= daysInMonth; i++) {
        const dateStr = `${currentYear.value}-${String(currentMonth.value).padStart(2, '0')}-${String(i).padStart(2, '0')}`
        days.push({
            day: i,
            dateStr: dateStr,
            isToday: isToday(dateStr),
            hasWorkout: props.workouts.some((w) => w.date === dateStr),
            hasJournal: props.journals.some((j) => j.date === dateStr),
            workouts: props.workouts.filter((w) => w.date === dateStr),
            journal: props.journals.find((j) => j.date === dateStr),
        })
    }

    return days
})

const isToday = (dateStr) => {
    const today = new Date()
    return (
        dateStr ===
        `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`
    )
}

const changeMonth = (delta) => {
    let newMonth = currentMonth.value + delta
    let newYear = currentYear.value

    if (newMonth > 12) {
        newMonth = 1
        newYear++
    } else if (newMonth < 1) {
        newMonth = 12
        newYear--
    }

    router.visit(route('calendar.index', { year: newYear, month: newMonth }), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            currentMonth.value = newMonth
            currentYear.value = newYear
            selectedDate.value = null // Reset selection on month change
        },
    })
}

const selectDate = (day) => {
    if (!day.day) return
    selectedDate.value = day
}

const selectedDayDetails = computed(() => {
    if (!selectedDate.value) return null
    return selectedDate.value
})

const formatDateFull = (dateStr) => {
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })
}
</script>

<template>
    <Head title="Calendrier" />

    <AuthenticatedLayout page-title="Calendrier">
        <template #header-actions>
            <GlassButton
                @click="changeMonth(0)"
                class="px-3!"
                v-if="currentMonth !== new Date().getMonth() + 1 || currentYear !== new Date().getFullYear()"
            >
                Aujourd'hui
            </GlassButton>
        </template>

        <div class="space-y-6">
            <!-- Calendar Navigation -->
            <div class="flex items-center justify-between">
                <GlassButton @click="changeMonth(-1)" class="px-3!" aria-label="Mois précédent">
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                </GlassButton>

                <h2 class="text-text-main text-xl font-black tracking-tighter uppercase italic">
                    {{ currentMonthName }} <span class="text-electric-orange">{{ currentYear }}</span>
                </h2>

                <GlassButton @click="changeMonth(1)" class="px-3!" aria-label="Mois suivant">
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </GlassButton>
            </div>

            <!-- Calendar Grid -->
            <GlassCard class="overflow-hidden" padding="p-0">
                <!-- Weekday Headers -->
                <div class="grid grid-cols-7 border-b border-white/40 bg-white/40 py-2">
                    <div
                        v-for="day in weekDays"
                        :key="day"
                        class="text-text-muted text-center text-[10px] font-bold tracking-wider uppercase"
                    >
                        {{ day }}
                    </div>
                </div>

                <!-- Days -->
                <div class="grid grid-cols-7">
                    <div
                        v-for="(day, index) in calendarGrid"
                        :key="index"
                        @click="selectDate(day)"
                        :class="[
                            'relative flex aspect-square cursor-pointer flex-col items-center justify-center border-r border-b border-white/5 transition-all hover:bg-white/10',
                            day.day ? '' : 'pointer-events-none',
                            selectedDate?.dateStr === day.dateStr ? 'bg-white/15 shadow-inner' : '',
                            (index + 1) % 7 === 0 ? 'border-r-0' : '', // Remove right border for last column
                        ]"
                    >
                        <div v-if="day.day">
                            <!-- Date Number -->
                            <span
                                :class="[
                                    'flex h-6 w-6 items-center justify-center rounded-full text-sm font-bold',
                                    day.isToday ? 'bg-electric-orange text-white' : 'text-text-main',
                                ]"
                            >
                                {{ day.day }}
                            </span>

                            <!-- Indicators -->
                            <div class="mt-1 flex gap-1">
                                <span
                                    v-if="day.hasWorkout"
                                    class="bg-vivid-violet h-1.5 w-1.5 rounded-full shadow-[0_0_4px_rgba(136,0,255,0.8)]"
                                ></span>
                                <span
                                    v-if="day.hasJournal"
                                    class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_4px_rgba(52,211,153,0.8)]"
                                ></span>
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- Selected Day Details -->
            <div v-if="selectedDayDetails" class="animate-slide-up space-y-4">
                <div class="flex items-center gap-2">
                    <div class="via-text-muted/10 h-px flex-1 bg-linear-to-r from-transparent to-transparent"></div>
                    <h3 class="font-display text-text-main text-lg font-bold capitalize">
                        {{ formatDateFull(selectedDayDetails.dateStr) }}
                    </h3>
                    <div class="via-text-muted/10 h-px flex-1 bg-linear-to-r from-transparent to-transparent"></div>
                </div>

                <!-- Workouts -->
                <div v-if="selectedDayDetails.workouts.length > 0" class="space-y-3">
                    <h4 class="text-text-muted text-xs font-black tracking-widest uppercase">Séances</h4>
                    <Link
                        v-for="workout in selectedDayDetails.workouts"
                        :key="workout.id"
                        :href="route('workouts.show', workout.id)"
                        class="block"
                    >
                        <GlassCard class="hover:bg-glass-strong transition active:scale-[0.99]" padding="p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="bg-vivid-violet/20 text-vivid-violet flex h-10 w-10 items-center justify-center rounded-xl"
                                    >
                                        <span class="material-symbols-outlined">fitness_center</span>
                                    </div>
                                    <div>
                                        <div class="text-text-main font-bold">{{ workout.name }}</div>
                                        <div class="text-text-muted text-xs">
                                            {{ workout.exercises_count }} exercices
                                            <span v-if="workout.preview_exercises.length" class="text-text-muted/60"
                                                >• {{ workout.preview_exercises.join(', ') }}</span
                                            >
                                        </div>
                                    </div>
                                </div>
                                <span class="material-symbols-outlined text-white/30">chevron_right</span>
                            </div>
                        </GlassCard>
                    </Link>
                </div>

                <!-- Journal -->
                <div v-if="selectedDayDetails.journal" class="space-y-3">
                    <h4 class="text-text-muted text-xs font-black tracking-widest uppercase">Journal</h4>
                    <GlassCard padding="p-4">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400"
                            >
                                <span class="material-symbols-outlined">menu_book</span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-text-main font-bold">Entrée Journal</span>
                                    <span
                                        v-if="selectedDayDetails.journal.mood_score"
                                        class="text-text-main rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold"
                                    >
                                        Humeur: {{ selectedDayDetails.journal.mood_score }}/10
                                    </span>
                                </div>
                                <div v-if="selectedDayDetails.journal.has_note" class="text-text-muted mt-1 text-sm">
                                    Notes ajoutées...
                                </div>
                                <div v-else class="text-text-muted/60 mt-1 text-sm italic">Aucune note écrite.</div>
                            </div>
                        </div>
                    </GlassCard>
                </div>

                <!-- Empty State -->
                <div
                    v-if="!selectedDayDetails.workouts.length && !selectedDayDetails.journal"
                    class="text-text-muted/60 py-8 text-center"
                >
                    <span class="material-symbols-outlined mb-2 text-4xl opacity-50">event_busy</span>
                    <p>Aucune activité ce jour-là.</p>
                </div>
            </div>

            <div v-else class="text-text-muted/40 py-12 text-center">
                <span class="material-symbols-outlined mb-2 animate-pulse text-4xl">touch_app</span>
                <p>Sélectionne une date pour voir les détails</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
