<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'

const WeeklyVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/WeeklyVolumeChart.vue'))
const DurationDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/DurationDistributionChart.vue'))

/**
 * Dashboard - Command Center
 * New Liquid Glass Light design with redesigned layout matching mockups.
 */
const props = defineProps({
    workoutsCount: { type: Number, default: 0 },
    thisWeekCount: { type: Number, default: 0 },
    latestWeight: { type: Number, default: null },
    recentWorkouts: { type: Array, default: () => [] },
    recentPRs: { type: Array, default: () => [] },
    activeGoals: { type: Array, default: () => [] },
    weeklyVolume: { type: Number, default: 0 },
    volumeChange: { type: Number, default: 0 },
    weeklyVolumeTrend: { type: Array, default: () => [] },
    volumeTrend: { type: Array, default: () => [] },
    durationDistribution: { type: Array, default: () => [] },
})

const form = useForm({})

const startWorkout = () => {
    form.post(route('workouts.store'))
}

const colorForWorkout = (index) => {
    const colors = ['violet', 'orange', 'pink', 'cyan', 'green']
    return colors[index % colors.length]
}
</script>

<template>
    <Head title="Accueil" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <!-- Header with Avatar & Streak -->
            <header class="animate-fade-in flex items-center justify-between py-4">
                <div class="flex items-center gap-4">
                    <!-- Avatar with gradient border -->
                    <div class="relative">
                        <div
                            class="from-electric-orange to-vivid-violet relative size-14 overflow-hidden rounded-full bg-linear-to-tr p-[2px]"
                        >
                            <div
                                class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white dark:border-slate-700 dark:bg-slate-800"
                            >
                                <span class="text-gradient text-2xl font-black">
                                    {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="text-text-muted mb-0.5 text-[10px] font-black tracking-[0.2em] uppercase">
                            BON RETOUR
                        </p>
                        <h1
                            class="font-display text-text-main text-3xl font-black tracking-tighter uppercase italic dark:text-white"
                        >
                            {{ $page.props.auth.user.name?.split(' ')[0] }}
                        </h1>
                    </div>
                </div>

                <!-- Streak Badge -->
                <div class="streak-badge cursor-pointer transition-transform hover:scale-105">
                    <span
                        class="material-symbols-outlined text-electric-orange text-[24px]"
                        style="font-variation-settings: 'FILL' 1"
                        >local_fire_department</span
                    >
                    <span class="text-text-main text-xl font-black italic dark:text-white">
                        {{ $page.props.auth.user.current_streak || 0 }}
                        <span class="text-text-muted ml-0.5 text-[10px] font-bold uppercase not-italic">Jours</span>
                    </span>
                </div>
            </header>

            <!-- Quick Actions (Two Big Cards) -->
            <section class="animate-slide-up grid grid-cols-2 gap-4" style="animation-delay: 0.1s">
                <!-- Start Workout -->
                <button
                    @click="startWorkout"
                    :disabled="form.processing"
                    class="hover:shadow-glow-orange/70 group shadow-glow-orange relative h-52 overflow-hidden rounded-3xl transition-all duration-300 active:scale-95"
                >
                    <div class="absolute inset-0 z-0 bg-white/60 backdrop-blur-md dark:bg-slate-800/60"></div>
                    <div
                        class="from-electric-orange/10 absolute inset-0 z-0 bg-linear-to-br to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                    ></div>
                    <div
                        class="border-electric-orange/20 group-hover:border-electric-orange/60 absolute inset-0 z-10 rounded-3xl border-2 transition-colors"
                    ></div>
                    <div class="relative z-20 flex h-full flex-col items-center justify-center gap-3 p-4">
                        <div
                            class="from-electric-orange to-hot-pink flex size-16 items-center justify-center rounded-2xl bg-linear-to-br shadow-lg shadow-orange-500/30 transition-transform duration-300 group-hover:scale-110"
                        >
                            <span class="material-symbols-outlined text-4xl text-white">fitness_center</span>
                        </div>
                        <span
                            class="font-display text-text-main text-center text-xl leading-none font-black tracking-tight uppercase italic dark:text-white"
                        >
                            Démarrer<br />Séance
                        </span>
                    </div>
                </button>

                <!-- Templates -->
                <Link
                    :href="route('templates.index')"
                    class="hover:shadow-glow-violet/70 group shadow-glow-violet relative h-52 overflow-hidden rounded-3xl transition-all duration-300 active:scale-95"
                >
                    <div class="absolute inset-0 z-0 bg-white/60 backdrop-blur-md dark:bg-slate-800/60"></div>
                    <div
                        class="from-vivid-violet/10 absolute inset-0 z-0 bg-linear-to-br to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"
                    ></div>
                    <div
                        class="border-vivid-violet/20 group-hover:border-vivid-violet/60 absolute inset-0 z-10 rounded-3xl border-2 transition-colors"
                    ></div>
                    <div class="relative z-20 flex h-full flex-col items-center justify-center gap-3 p-4">
                        <div
                            class="from-vivid-violet to-hot-pink flex size-16 items-center justify-center rounded-2xl bg-linear-to-br shadow-lg shadow-purple-500/30 transition-transform duration-300 group-hover:scale-110"
                        >
                            <span class="material-symbols-outlined text-4xl text-white">assignment_add</span>
                        </div>
                        <span
                            class="font-display text-text-main text-center text-xl leading-none font-black tracking-tight uppercase italic dark:text-white"
                        >
                            Mes<br />Programmes
                        </span>
                    </div>
                </Link>
            </section>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Weekly Volume Chart Card -->
                <section
                    class="glass-panel-light animate-slide-up relative overflow-hidden rounded-3xl p-6"
                    style="animation-delay: 0.15s"
                >
                    <div class="relative z-10 mb-6 flex items-start justify-between">
                        <div>
                            <h3 class="text-electric-orange mb-1 text-[10px] font-black tracking-[0.2em] uppercase">
                                Aperçu
                            </h3>
                            <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                                Volume Hebdo
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                class="from-electric-orange to-vivid-violet font-display bg-linear-to-r bg-clip-text text-4xl font-black tracking-tighter text-transparent"
                            >
                                {{ weeklyVolume?.toLocaleString() || thisWeekCount * 1000 }}
                            </p>
                            <p
                                v-if="volumeChange !== 0"
                                :class="[
                                    'mt-1 flex items-center justify-end gap-1 text-xs font-bold tracking-wide uppercase',
                                    volumeChange > 0 ? 'text-emerald-600' : 'text-red-500',
                                ]"
                            >
                                <span class="material-symbols-outlined text-sm font-bold">
                                    {{ volumeChange > 0 ? 'trending_up' : 'trending_down' }}
                                </span>
                                {{ volumeChange > 0 ? '+' : '' }}{{ volumeChange }}% vs sem. passée
                            </p>
                        </div>
                    </div>

                    <!-- Weekly Volume Chart -->
                    <div class="relative -mx-2 mt-2 h-48 w-auto">
                        <WeeklyVolumeChart
                            v-if="weeklyVolumeTrend && weeklyVolumeTrend.length > 0"
                            :data="weeklyVolumeTrend"
                        />
                        <div v-else class="text-text-muted flex h-full items-center justify-center">
                            <p class="text-sm">Pas de données cette semaine</p>
                        </div>
                    </div>
                </section>

                <!-- Duration Distribution Chart -->
                <section
                    class="glass-panel-light animate-slide-up relative overflow-hidden rounded-3xl p-6"
                    style="animation-delay: 0.17s"
                >
                    <div class="relative z-10 mb-6">
                        <h3 class="text-cyan-pure mb-1 text-[10px] font-black tracking-[0.2em] uppercase">
                            Répartition
                        </h3>
                        <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                            Durée Séances
                        </p>
                    </div>

                    <!-- Duration Chart -->
                    <div class="relative -mx-2 mt-2 h-48 w-auto">
                        <DurationDistributionChart
                            v-if="durationDistribution && durationDistribution.some((d) => d.count > 0)"
                            :data="durationDistribution"
                        />
                        <div v-else class="text-text-muted flex h-full items-center justify-center">
                            <p class="text-sm">Pas assez de données (90j)</p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Recent Activity -->
            <section class="animate-slide-up" style="animation-delay: 0.2s">
                <div class="mb-5 flex items-center justify-between px-1">
                    <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Activité Récente</h3>
                    <Link
                        :href="route('workouts.index')"
                        class="text-electric-orange hover:text-vivid-violet text-xs font-bold tracking-wider uppercase transition-colors"
                    >
                        Voir tout
                    </Link>
                </div>

                <!-- Empty State -->
                <div v-if="recentWorkouts.length === 0" class="glass-panel-light rounded-3xl p-8 text-center">
                    <div class="mb-3 text-5xl">🏋️</div>
                    <p class="text-text-main font-bold dark:text-white">Aucune séance pour l'instant</p>
                    <p class="text-text-muted mt-1 text-sm">Commence ton parcours fitness !</p>
                </div>

                <!-- Activity Cards -->
                <div v-else class="flex flex-col gap-3">
                    <Link
                        v-for="(workout, index) in recentWorkouts.slice(0, 3)"
                        :key="workout.id"
                        :href="route('workouts.show', { workout: workout.id })"
                        class="activity-card"
                        :data-color="colorForWorkout(index)"
                    >
                        <div class="flex items-center gap-4 pl-3">
                            <div
                                :class="[
                                    'flex size-12 items-center justify-center rounded-xl border',
                                    index === 0
                                        ? 'text-vivid-violet border-violet-100 bg-violet-50'
                                        : index === 1
                                          ? 'text-electric-orange border-orange-100 bg-orange-50'
                                          : 'text-hot-pink border-pink-100 bg-pink-50',
                                ]"
                            >
                                <span class="material-symbols-outlined">
                                    {{ (workout.workout_lines_count || 0) > 3 ? 'timer' : 'fitness_center' }}
                                </span>
                            </div>
                            <div>
                                <h4
                                    class="font-display text-text-main text-lg leading-tight font-bold uppercase italic dark:text-white"
                                >
                                    {{ workout.name || 'Séance' }}
                                </h4>
                                <p class="text-text-muted mt-1 text-xs font-bold">
                                    {{
                                        new Date(workout.started_at).toLocaleDateString('fr-FR', {
                                            weekday: 'long',
                                            day: 'numeric',
                                            month: 'short',
                                        })
                                    }}
                                    •
                                    {{
                                        workout.duration_minutes ||
                                        (workout.ended_at
                                            ? Math.round(
                                                  (new Date(workout.ended_at) - new Date(workout.started_at)) / 60000,
                                              )
                                            : null) ||
                                        '--'
                                    }}
                                    min
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span v-if="workout.ended_at" class="glass-badge glass-badge-success">Fait</span>
                            <span v-else class="glass-badge glass-badge-warning animate-pulse">En cours</span>
                            <span class="text-text-muted mt-1 font-mono text-xs">
                                {{
                                    new Date(workout.started_at).toLocaleTimeString('fr-FR', {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })
                                }}
                            </span>
                        </div>
                    </Link>
                </div>
            </section>

            <!-- Goals Summary (Compact) -->
            <section v-if="activeGoals.length > 0" class="animate-slide-up" style="animation-delay: 0.25s">
                <div class="mb-4 flex items-center justify-between px-1">
                    <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Objectifs en cours</h3>
                    <Link
                        :href="route('goals.index')"
                        class="text-electric-orange text-xs font-bold tracking-wider uppercase"
                    >
                        Voir tout
                    </Link>
                </div>

                <div class="space-y-3">
                    <Link
                        v-for="goal in activeGoals.slice(0, 2)"
                        :key="goal.id"
                        :href="route('goals.index')"
                        class="block"
                    >
                        <GlassCard :hover="true" padding="p-4">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-text-main line-clamp-1 text-sm font-bold">{{ goal.title }}</span>
                                <span class="text-electric-orange text-xs font-black"
                                    >{{ Math.round(goal.progress) }}%</span
                                >
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div
                                    class="glow-orange bg-gradient-main h-full transition-all duration-1000"
                                    :style="{ width: goal.progress + '%' }"
                                ></div>
                            </div>
                        </GlassCard>
                    </Link>
                </div>
            </section>

            <!-- Recent PRs -->
            <section v-if="recentPRs.length > 0" class="animate-slide-up" style="animation-delay: 0.3s">
                <div class="mb-4 flex items-center justify-between px-1">
                    <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Records personnels</h3>
                </div>

                <div class="space-y-3">
                    <GlassCard v-for="pr in recentPRs.slice(0, 2)" :key="pr.id" padding="p-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-yellow-400 to-orange-500 shadow-lg"
                                >
                                    <span class="material-symbols-outlined text-2xl text-white">star</span>
                                </div>
                                <div>
                                    <div class="text-text-main font-bold">{{ pr.exercise.name }}</div>
                                    <div class="text-text-muted text-xs">
                                        {{
                                            pr.type === 'max_weight'
                                                ? 'Poids Max'
                                                : pr.type === 'max_1rm'
                                                  ? '1RM Estimé'
                                                  : 'Volume'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-display text-electric-orange text-2xl font-black">
                                    {{ pr.value
                                    }}<span class="text-text-muted text-sm">{{
                                        pr.type === 'max_volume_set' ? '' : 'kg'
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
