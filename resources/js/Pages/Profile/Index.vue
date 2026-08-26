<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
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
            // These four features are routed, tested and fully working, but nothing
            // in the app linked to them — they were reachable only by typing the URL.
            {
                name: 'Mensurations',
                icon: 'accessibility_new',
                route: 'body-parts.index',
                description: 'Tour de bras, taille…',
                color: 'text-rose-500',
                bgColor: 'bg-rose-100/50',
            },
            {
                name: 'Habitudes',
                icon: 'task_alt',
                route: 'habits.index',
                description: 'Suivi quotidien',
                color: 'text-violet-500',
                bgColor: 'bg-violet-100/50',
            },
            {
                name: 'Journal',
                icon: 'menu_book',
                route: 'daily-journals.index',
                description: 'Notes et ressenti',
                color: 'text-teal-500',
                bgColor: 'bg-teal-100/50',
            },
            {
                name: 'Compléments',
                icon: 'medication',
                route: 'supplements.index',
                description: 'Suivi des prises',
                color: 'text-lime-600',
                bgColor: 'bg-lime-100/50',
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
                    class="bg-gradient-main shadow-accent-primary/20 flex h-20 w-20 items-center justify-center rounded-3xl p-[3px] shadow-lg"
                >
                    <div
                        class="bg-surface-card flex h-full w-full items-center justify-center rounded-[1.2rem] text-3xl font-black"
                    >
                        {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                    </div>
                </div>
                <div>
                    <h2 class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic">
                        {{ $page.props.auth.user.name }}
                    </h2>
                    <p class="text-text-muted text-sm font-bold">{{ $page.props.auth.user.email }}</p>
                    <div class="mt-2 flex gap-2">
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="text-accent-danger text-xs font-black tracking-widest uppercase hover:text-red-600"
                            data-testid="logout-button"
                        >
                            Déconnexion
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Theme Toggle -->
            <div class="animate-fade-in"></div>

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
                    <Link
                        v-for="item in group.items"
                        :key="item.name"
                        :href="route(item.route)"
                        class="group focus-visible:ring-electric-orange block rounded-2xl transition-all focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <GlassCard
                            padding="p-4"
                            :hover="true"
                            class="border-surface-card/40 bg-surface-card/60 rounded-2xl!"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    :class="[
                                        'flex h-12 w-12 items-center justify-center rounded-xl transition-transform group-active:scale-95',
                                        item.bgColor,
                                        item.color,
                                    ]"
                                >
                                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">{{
                                        item.icon
                                    }}</span>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-text-main font-bold">{{ item.name }}</h4>
                                    <p class="text-text-muted text-xs">{{ item.description }}</p>
                                </div>
                                <span
                                    class="material-symbols-outlined text-text-muted/30 group-hover:text-text-main transition-all group-hover:translate-x-1"
                                    aria-hidden="true"
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
