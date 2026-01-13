<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
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
            <div class="grid animate-slide-up grid-cols-3 gap-3" style="animation-delay: 0.1s">
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-gradient text-2xl font-bold">{{ workoutsCount }}</div>
                        <div class="mt-1 text-xs text-white/60">Séances</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent-success">{{ thisWeekCount }}</div>
                        <div class="mt-1 text-xs text-white/60">Cette semaine</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent-info">
                            {{ latestWeight ? `${latestWeight}` : '—' }}
                        </div>
                        <div class="mt-1 text-xs text-white/60">kg</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Quick Actions -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
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
                    Commencer une séance
                </GlassButton>
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

            <!-- Quick Links -->
            <div class="grid animate-slide-up grid-cols-2 gap-3" style="animation-delay: 0.4s">
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
                                        d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                                    />
                                </svg>
                            </div>
                            <div class="text-sm font-medium text-white">Suivi Corps</div>
                        </div>
                    </GlassCard>
                </Link>
                <Link :href="route('profile.edit')">
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
