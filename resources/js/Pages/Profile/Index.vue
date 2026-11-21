<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { Head, Link } from '@inertiajs/vue3'
import { seDeconnecter } from '@/composables/useDeconnexion'

const menuGroups = [
    {
        title: 'Navigation',
        items: [
            {
                name: 'Calendrier',
                icon: 'calendar_month',
                route: 'calendar.index',
                description: 'Vue calendrier des séances',
                color: 'text-accent-primary-deep',
                bgColor: 'bg-accent-primary/50',
            },
            {
                name: 'Exercices',
                icon: 'library_books',
                route: 'exercises.index',
                description: 'Gérer ta bibliothèque',
                color: 'text-accent-primary-deep',
                bgColor: 'bg-accent-primary/50',
            },
            {
                name: 'Outils',
                icon: 'handyman',
                route: 'tools.index',
                description: 'Calculatrices et utilitaires',
                color: 'text-accent-tertiary-deep',
                bgColor: 'bg-accent-tertiary/50',
            },
        ],
    },
    {
        title: 'Ma progression',
        items: [
            {
                name: 'Badges',
                icon: 'emoji_events',
                route: 'achievements.index',
                description: 'Voir tes exploits',
                color: 'text-accent-warning-deep',
                bgColor: 'bg-accent-warning/50',
            },
            {
                name: 'Objectifs',
                icon: 'tour',
                route: 'goals.index',
                description: 'Gérer tes objectifs',
                color: 'text-accent-state-deep',
                bgColor: 'bg-accent-state/50',
            },
            {
                name: 'Corps',
                icon: 'accessibility_new',
                route: 'corps.index',
                description: 'Poids, masse grasse, mensurations',
                color: 'text-accent-secondary-deep',
                bgColor: 'bg-accent-secondary/50',
            },
            {
                name: 'Habitudes',
                icon: 'task_alt',
                route: 'habits.index',
                description: 'Suivi quotidien',
                color: 'text-accent-tertiary-deep',
                bgColor: 'bg-accent-tertiary/50',
            },
            {
                name: 'Journal',
                icon: 'menu_book',
                route: 'daily-journals.index',
                description: 'Notes et ressenti',
                color: 'text-accent-primary-deep',
                bgColor: 'bg-accent-primary/50',
            },
            {
                name: 'Compléments',
                icon: 'medication',
                route: 'supplements.index',
                description: 'Suivi des prises',
                color: 'text-accent-state-deep',
                bgColor: 'bg-accent-state/50',
            },
        ],
    },
    {
        title: 'Compte',
        items: [
            {
                name: 'Modifier le profil',
                icon: 'person_edit',
                route: 'profile.edit',
                description: 'Infos et préférences',
                color: 'text-text-main',
                bgColor: 'bg-surface-sunken/50',
            },
            {
                name: 'Raccourcis',
                icon: 'keyboard',
                route: 'shortcuts.index',
                description: 'Ce que le clavier sait faire',
                color: 'text-text-main',
                bgColor: 'bg-surface-sunken/50',
            },
        ],
    },
]
</script>

<template>
    <Head title="Menu" />

    <AuthenticatedLayout page-title="Profil" titre-masque>
        <div class="space-y-6">
            <!-- User Profile Quick View -->
            <div class="animate-fade-in flex items-center gap-4 py-2">
                <div
                    class="bg-surface-sunken font-display text-text-main flex h-20 w-20 items-center justify-center rounded-3xl text-3xl font-semibold"
                >
                    {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                </div>
                <div>
                    <h2 class="titre-ecran">
                        {{ $page.props.auth.user.name }}
                    </h2>
                    <p class="text-text-muted text-sm font-medium">{{ $page.props.auth.user.email }}</p>
                    <div class="mt-2 flex gap-2">
                        <!--
                            Le bouton que rendait `<Link as="button">`, à l'identique : la
                            déconnexion détache d'abord l'appareil du compte (#1926), ce
                            qu'un lien Inertia ne sait pas attendre. Sous 1 024 px, c'est
                            la seule déconnexion de l'application : il offre au pouce les
                            44 px de la charte (`min-h-touch`), son texte centré (#2116).
                        -->
                        <button
                            type="button"
                            class="text-accent-danger-deep hover:text-accent-danger-deep min-h-touch inline-flex items-center text-sm font-semibold"
                            data-testid="logout-button"
                            @click="seDeconnecter"
                        >
                            Déconnexion
                        </button>
                    </div>
                </div>
            </div>

            <!-- Menu Groups -->
            <div
                v-for="(group, gIdx) in menuGroups"
                :key="group.title"
                class="animate-slide-up"
                :style="{ animationDelay: `${0.1 + gIdx * 0.1}s` }"
            >
                <h3 class="etiquette mb-4 ml-1">
                    {{ group.title }}
                </h3>

                <div class="bureau:grid-cols-2 grid grid-cols-1 gap-3">
                    <Link
                        v-for="item in group.items"
                        :key="item.name"
                        :href="route(item.route)"
                        class="group focus-visible:ring-accent-primary block rounded-2xl transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
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
                                    <GlassIcon :name="item.icon" />
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-text-main font-bold">{{ item.name }}</h4>
                                    <p class="text-text-muted text-xs">{{ item.description }}</p>
                                </div>
                                <GlassIcon
                                    name="chevron_right"
                                    class="text-text-muted/30 group-hover:text-text-main transition group-hover:translate-x-1"
                                />
                            </div>
                        </GlassCard>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
