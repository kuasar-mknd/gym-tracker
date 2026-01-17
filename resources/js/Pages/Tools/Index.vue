<template>
    <AuthenticatedLayout page-title="Outils" show-back back-route="profile.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('profile.index')"
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-text-muted shadow-sm transition-colors hover:text-electric-orange"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-xl font-semibold text-text-main">Outils</h2>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <!-- Plate Calculator -->
            <Link :href="route('plates.index')" class="group">
                <GlassCard
                    class="relative h-full overflow-hidden p-6 transition-all duration-300 group-hover:bg-white/10"
                >
                    <div class="flex flex-col items-center space-y-4 text-center">
                        <div class="rounded-full bg-accent-primary/20 p-4 text-accent-primary">
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
                        <h3 class="text-xl font-bold text-text-main">Plate Calculator</h3>
                        <p class="text-sm text-text-muted">
                            Calculate which plates to load on the bar for a specific weight.
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
                        <h3 class="text-xl font-bold text-text-main">1RM Calculator</h3>
                        <p class="text-sm text-text-muted">
                            Estimate your One Rep Max based on your lifted weight and reps.
                        </p>
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
