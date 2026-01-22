<template>
    <AuthenticatedLayout page-title="Outils" show-back back-route="profile.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('profile.index')"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-text-main text-xl font-semibold">Outils</h2>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <!-- Plate Calculator -->
            <Link :href="route('plates.index')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="bg-accent-primary/20 text-accent-primary rounded-full p-4">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
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
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Calculateur de Plaques</h3>
                        <p class="text-text-muted text-sm">
                            Calcule quelles plaques charger sur la barre pour un poids spécifique.
                        </p>
                    </div>
                </GlassCard>
            </Link>

            <!-- 1RM Calculator -->
            <Link :href="route('tools.1rm')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="bg-accent-secondary/20 text-accent-secondary rounded-full p-4">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
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
                        <h3 class="text-text-main text-xl font-bold">Calculateur 1RM</h3>
                        <p class="text-text-muted text-sm">
                            Estime ton maximum sur une répétition basé sur le poids soulevé et les répétitions.
                        </p>
                    </div>
                </GlassCard>
            </Link>

            <!-- Wilks Calculator -->
            <Link :href="route('tools.wilks')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="bg-electric-orange/20 text-electric-orange rounded-full p-4">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 10V3L4 14h7v7l9-11h-7z"
                                />
                            </svg>
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Calculateur Wilks</h3>
                        <p class="text-text-muted text-sm">
                            Calcule ton score de force relative indépendamment de ton poids de corps.
                        </p>
                    </div>
                </GlassCard>
            </Link>

            <!-- Macro Calculator -->
            <Link :href="route('tools.macro-calculator')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="rounded-full bg-emerald-500/20 p-4 text-emerald-500">
                            <span class="material-symbols-outlined text-3xl">local_fire_department</span>
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Calculateur de Macros</h3>
                        <p class="text-text-muted text-sm">
                            Estime tes besoins quotidiens en calories et macros selon tes objectifs.
                        </p>
                    </div>
                </GlassCard>
            </Link>

            <!-- Warmup Calculator -->
            <Link :href="route('tools.warmup')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="rounded-full bg-blue-500/20 p-4 text-blue-500">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
                                />
                            </svg>
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Calculateur d'Échauffement</h3>
                        <p class="text-text-muted text-sm">
                            Calcule tes séries d'échauffement basées sur ton poids de travail.
                        </p>
                    </div>
                </GlassCard>
            </Link>

            <!-- Water Tracker -->
            <Link :href="route('tools.water.index')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="rounded-full bg-cyan-500/20 p-4 text-cyan-500">
                            <span class="material-symbols-outlined text-3xl">water_drop</span>
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Water Tracker</h3>
                        <p class="text-text-muted text-sm">Track your daily water intake and stay hydrated.</p>
                    </div>
                </GlassCard>
            </Link>

            <!-- Fasting Tracker -->
            <Link :href="route('fasting.index')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="rounded-full bg-purple-500/20 p-4 text-purple-500">
                            <span class="material-symbols-outlined text-3xl">timer</span>
                        </div>
                        <h3 class="text-text-main text-xl font-bold">Suivi de Jeûne</h3>
                        <p class="text-text-muted text-sm">Suivez vos périodes de jeûne intermittent.</p>
                    </div>
                </GlassCard>
            </Link>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
</script>
