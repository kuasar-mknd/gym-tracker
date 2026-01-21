<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import ThemeToggle from '@/Components/UI/ThemeToggle.vue'
import { Head, Link } from '@inertiajs/vue3'

const menuGroups = [
    {
        title: 'Navigation',
        items: [
            {
                name: 'Calendrier',
                icon: 'calendar_month',
                route: 'calendar.index',
                description: 'Vue calendrier des séances',
                color: 'text-blue-500',
                bgColor: 'bg-blue-100/50',
            },
            {
                name: 'Exercices',
                icon: 'library_books',
                route: 'exercises.index',
                description: 'Gérer ta bibliothèque',
                color: 'text-cyan-pure',
                bgColor: 'bg-cyan-100/50',
            },
            {
                name: 'Outils',
                icon: 'handyman',
                route: 'tools.index',
                description: 'Calculatrices & utilitaires',
                color: 'text-indigo-500',
                bgColor: 'bg-indigo-100/50',
            },
        ],
    },
    {
        title: 'Ma Progression',
        items: [
            {
                name: 'Trophées',
                icon: 'emoji_events',
                route: 'achievements.index',
                description: 'Voir tes exploits',
                color: 'text-amber-500',
                bgColor: 'bg-amber-100/50',
            },
            {
                name: 'Objectifs',
                icon: 'tour',
                route: 'goals.index',
                description: 'Gérer tes targets',
                color: 'text-emerald-500',
                bgColor: 'bg-emerald-100/50',
            },
            {
                name: 'Mesures',
                icon: 'straighten',
                route: 'body-measurements.index',
                description: 'Évolution corporelle',
                color: 'text-hot-pink',
                bgColor: 'bg-pink-100/50',
            },
        ],
    },
    {
        title: 'Compte',
        items: [
            {
                name: 'Modifier Profil',
                icon: 'person_edit',
                route: 'profile.edit',
                description: 'Infos & Préférences',
                color: 'text-text-main',
                bgColor: 'bg-slate-100/50',
            },
        ],
    },
]
</script>

<template>
    <Head title="Menu" />

    <AuthenticatedLayout page-title="Plus">
        <div class="space-y-8">
            <!-- User Profile Quick View -->
            <div class="animate-fade-in flex items-center gap-4 py-2">
                <div
                    class="bg-gradient-main flex h-20 w-20 items-center justify-center rounded-3xl p-[3px] shadow-lg shadow-orange-500/20"
                >
                    <div
                        class="flex h-full w-full items-center justify-center rounded-[1.2rem] bg-white text-3xl font-black dark:bg-slate-800"
                    >
                        {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                    </div>
                </div>
                <div>
                    <h2
                        class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic dark:text-white"
                    >
                        {{ $page.props.auth.user.name }}
                    </h2>
                    <p class="text-text-muted text-sm font-bold">{{ $page.props.auth.user.email }}</p>
                    <div class="mt-2 flex gap-2">
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="text-xs font-black tracking-widest text-red-500 uppercase hover:text-red-600"
                        >
                            Déconnexion
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Theme Toggle -->
            <div class="animate-fade-in">
                <ThemeToggle class="w-full" />
            </div>

            <!-- Menu Groups -->
            <div
                v-for="(group, gIdx) in menuGroups"
                :key="group.title"
                class="animate-slide-up"
                :style="{ animationDelay: `${0.1 + gIdx * 0.1}s` }"
            >
                <h3 class="text-text-muted mb-4 ml-1 text-[10px] font-black tracking-[0.2em] uppercase">
                    {{ group.title }}
                </h3>

                <div class="grid grid-cols-1 gap-3">
                    <Link v-for="item in group.items" :key="item.name" :href="route(item.route)" class="group block">
                        <GlassCard
                            padding="p-4"
                            :hover="true"
                            class="rounded-2xl! border-white/40 bg-white/60 dark:border-slate-700/40 dark:bg-slate-800/60"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    :class="[
                                        'flex h-12 w-12 items-center justify-center rounded-xl transition-transform group-active:scale-95',
                                        item.bgColor,
                                        item.color,
                                    ]"
                                >
                                    <span class="material-symbols-outlined text-2xl">{{ item.icon }}</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-text-main font-bold dark:text-white">{{ item.name }}</h4>
                                    <p class="text-text-muted text-xs">{{ item.description }}</p>
                                </div>
                                <span
                                    class="material-symbols-outlined text-text-muted/30 group-hover:text-text-main transition-all group-hover:translate-x-1"
                                >
                                    chevron_right
                                </span>
                            </div>
                        </GlassCard>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
