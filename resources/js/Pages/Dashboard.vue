<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import StreakCounter from '@/Components/Dashboard/StreakCounter.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    workoutsCount: {
        type: Number,
        default: 0,
    },
    thisWeekCount: {
        type: Number,
        default: 0,
    },
    latestWeight: {
        type: Number,
        default: null,
    },
    recentWorkouts: {
        type: Array,
        default: () => [],
    },
    recentPRs: {
        type: Array,
        default: () => [],
    },
    activeGoals: {
        type: Array,
        default: () => [],
    },
})

const form = useForm({})

const startWorkout = () => {
    form.post(route('workouts.store'))
}

const greeting = computed(() => {
    const hour = new Date().getHours()
    if (hour < 12) return 'Bonjour'
    if (hour < 18) return 'Bon après-midi'
    return 'Bonsoir'
})
</script>

<template>
    <Head title="Accueil" />

    <AuthenticatedLayout page-title="Accueil">
        <div class="space-y-6">
            <!-- Welcome Section -->
            <div class="animate-slide-up">
                <h1 class="text-2xl font-bold text-white">
                    {{ greeting }}, {{ $page.props.auth.user.name.split(' ')[0] }} 👋
                </h1>
                <p class="mt-1 text-white/60">Prêt pour ton entraînement ?</p>
            </div>

            <!-- Quick Stats -->
            <div class="grid animate-slide-up grid-cols-2 gap-3 sm:grid-cols-4" style="animation-delay: 0.1s">
                <StreakCounter :count="$page.props.auth.user.current_streak" />
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-gradient text-2xl font-bold">{{ workoutsCount }}</div>
                        <div class="mt-1 text-sm text-white/60">Séances</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent-success">{{ thisWeekCount }}</div>
                        <div class="mt-1 text-sm text-white/60">Cette semaine</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent-info">
                            {{ latestWeight ? `${latestWeight}` : '—' }}
                        </div>
                        <div class="mt-1 text-sm text-white/60">kg</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Quick Actions -->
            <div class="grid animate-slide-up grid-cols-2 gap-3" style="animation-delay: 0.2s">
                <GlassButton
                    variant="primary"
                    size="lg"
                    class="w-full"
                    :loading="form.processing"
                    @click="startWorkout"
                >
                    <svg
                        class="mr-2 h-5 w-5"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Libre
                </GlassButton>
                <Link :href="route('templates.index')" class="block w-full">
                    <GlassButton size="lg" class="w-full">
                        <svg
                            class="mr-2 h-5 w-5 text-accent-primary"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                            />
                        </svg>
                        Modèle
                    </GlassButton>
                </Link>
            </div>

            <!-- Recent Workouts -->
            <div class="animate-slide-up" style="animation-delay: 0.3s">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-white">Séances récentes</h2>
                    <Link :href="route('workouts.index')" class="text-sm text-accent-primary"> Voir tout → </Link>
                </div>

                <div v-if="recentWorkouts.length === 0">
                    <GlassCard>
                        <div class="py-8 text-center">
                            <div class="mb-2 text-4xl">🏋️</div>
                            <p class="text-white/60">Aucune séance pour l'instant</p>
                            <p class="mt-1 text-sm text-white/40">Commence ton parcours fitness !</p>
                        </div>
                    </GlassCard>
                </div>

                <div v-else class="space-y-3">
                    <Link
                        v-for="workout in recentWorkouts.slice(0, 3)"
                        :key="workout.id"
                        :href="route('workouts.show', workout.id)"
                        class="block"
                    >
                        <GlassCard class="transition hover:bg-glass-strong">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-semibold text-white">
                                        {{ workout.name || 'Séance' }}
                                    </div>
                                    <div class="mt-0.5 text-sm text-white/50">
                                        {{
                                            new Date(workout.started_at).toLocaleDateString('fr-FR', {
                                                weekday: 'short',
                                                day: 'numeric',
                                                month: 'short',
                                            })
                                        }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="glass-badge glass-badge-primary">
                                        {{ workout.workout_lines?.length || 0 }} exo
                                    </span>
                                    <svg
                                        class="h-5 w-5 text-white/40"
                                        xmlns="http://www.w3.org/2000/svg"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5l7 7-7 7"
                                        />
                                    </svg>
                                </div>
                            </div>
                        </GlassCard>
                    </Link>
                </div>
            </div>

            <!-- Goals Summary -->
            <div v-if="activeGoals.length > 0" class="animate-slide-up" style="animation-delay: 0.32s">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-white">Objectifs en cours 🎯</h2>
                    <Link :href="route('goals.index')" class="text-xs text-accent-primary"> Mes objectifs → </Link>
                </div>

                <div class="space-y-3">
                    <Link v-for="goal in activeGoals" :key="goal.id" :href="route('goals.index')" class="block">
                        <GlassCard padding="p-3" class="transition hover:bg-glass-strong">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="line-clamp-1 text-sm font-semibold text-white">{{ goal.title }}</span>
                                </div>
                                <span class="text-xs font-bold text-accent-primary"
                                    >{{ Math.round(goal.progress) }}%</span
                                >
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/5">
                                <div
                                    class="h-full bg-accent-primary transition-all duration-1000"
                                    :style="{ width: goal.progress + '%' }"
                                ></div>
                            </div>
                        </GlassCard>
                    </Link>
                </div>
            </div>

            <!-- Recent Personal Records -->
            <div v-if="recentPRs.length > 0" class="animate-slide-up" style="animation-delay: 0.35s">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-white">Records personnels</h2>
                </div>

                <div class="space-y-3">
                    <GlassCard v-for="pr in recentPRs" :key="pr.id" padding="p-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-warning/20">
                                    <span class="text-xl">🏆</span>
                                </div>
                                <div>
                                    <div class="font-semibold text-white">{{ pr.exercise.name }}</div>
                                    <div class="text-xs text-white/50">
                                        {{
                                            pr.type === 'max_weight'
                                                ? 'Poids Maximum'
                                                : pr.type === 'max_1rm'
                                                  ? '1RM Estimé'
                                                  : 'Volume'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-bold text-accent-warning">
                                    {{ pr.value }}{{ pr.type === 'max_volume_set' ? '' : 'kg' }}
                                </div>
                                <div class="text-[10px] text-white/40">
                                    {{ new Date(pr.achieved_at).toLocaleDateString('fr-FR') }}
                                </div>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="grid animate-slide-up grid-cols-2 gap-3 sm:grid-cols-3" style="animation-delay: 0.4s">
                <Link :href="route('stats.index')">
                    <GlassCard class="transition hover:bg-glass-strong">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-primary/20">
                                <svg
                                    class="h-5 w-5 text-accent-primary"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                                    />
                                </svg>
                            </div>
                            <div class="text-sm font-medium text-white">Analyses</div>
                        </div>
                    </GlassCard>
                </Link>
                <Link :href="route('body-measurements.index')">
                    <GlassCard class="transition hover:bg-glass-strong">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-info/20">
                                <svg
                                    class="h-5 w-5 text-accent-info"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0l-3-9m3 1c1 0 1 1 1 1m0 0l3 9a5.002 5.002 0 006.001 0l-3-9m-3 1c-1 0-1-1-1-1m-4-1V5a2 2 0 012-2h2a2 2 0 012 2v3m-6 0h6"
                                    />
                                </svg>
                            </div>
                            <div class="text-sm font-medium text-white">Corps</div>
                        </div>
                    </GlassCard>
                </Link>
                <Link :href="route('daily-journals.index')">
                    <GlassCard class="transition hover:bg-glass-strong">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-pink-500/20">
                                <svg
                                    class="h-5 w-5 text-pink-400"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
                                    />
                                </svg>
                            </div>
                            <div class="text-sm font-medium text-white">Journal</div>
                        </div>
                    </GlassCard>
                </Link>
                <Link :href="route('profile.edit')" class="col-span-2 sm:col-span-1">
                    <GlassCard class="transition hover:bg-glass-strong">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/5">
                                <svg
                                    class="h-5 w-5 text-white/70"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                    />
                                </svg>
                            </div>
                            <div class="text-sm font-medium text-white">Mon Profil</div>
                        </div>
                    </GlassCard>
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
