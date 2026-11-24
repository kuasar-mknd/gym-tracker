<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import Cloche from '@/Components/Navigation/Cloche.vue'
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { seDeconnecter } from '@/composables/useDeconnexion'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'

const { surUneCopie } = useCopieHorsLigne()

const menuGroups = [
    {
        title: 'Outils et suivi',
        items: [
            { name: 'Outils', icon: 'handyman', route: 'tools.index' },
            { name: 'Habitudes', icon: 'task_alt', route: 'habits.index' },
            { name: 'Journal', icon: 'menu_book', route: 'daily-journals.index' },
            { name: 'Compléments', icon: 'medication', route: 'supplements.index' },
        ],
    },
    {
        title: 'Compte',
        items: [
            { name: 'Paramètres', icon: 'settings', route: 'profile.edit' },
            { name: 'Raccourcis clavier', icon: 'keyboard', route: 'shortcuts.index', bureauSeulement: true },
        ],
    },
]

/**
 * Sans réseau, seul « Outils » s'ouvre : les autres pages n'ont pas de copie
 * (`Utils/pagesGardees.js`), et leurs rangées mèneraient à « Hors ligne ».
 */
const groupes = computed(() =>
    surUneCopie.value
        ? [
              { ...menuGroups[0], items: menuGroups[0].items.slice(0, 1) },
              { ...menuGroups[1], items: [] },
          ]
        : menuGroups,
)

const rangee =
    'border-border focus-visible:ring-accent-primary min-h-14 items-center gap-4 border-t px-3 first:border-t-0 focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset'
</script>

<template>
    <Head title="Profil" />

    <AuthenticatedLayout page-title="Profil" titre-masque>
        <div class="flex flex-col gap-4">
            <GlassCard class="relative" padding="p-4">
                <div class="bureau:hidden absolute top-3 right-3 z-10"><Cloche /></div>

                <div class="bureau:pe-0 flex items-center gap-4 pe-14">
                    <div
                        class="bg-surface-sunken font-display text-text-main flex size-14 shrink-0 items-center justify-center rounded-2xl text-2xl font-semibold"
                    >
                        {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="titre-plat truncate">{{ $page.props.auth.user.name }}</h2>
                        <p class="text-text-muted truncate text-sm">{{ $page.props.auth.user.email }}</p>
                    </div>
                </div>
            </GlassCard>

            <div class="bureau:grid-cols-2 bureau:items-start grid grid-cols-1 gap-4">
                <div v-for="(groupe, rang) in groupes" :key="groupe.title" class="flex flex-col gap-4">
                    <GlassCard v-if="groupe.items.length > 0" as="nav" :aria-label="groupe.title" padding="p-1.5">
                        <Link
                            v-for="item in groupe.items"
                            :key="item.route"
                            v-press
                            :href="route(item.route)"
                            :class="[rangee, item.bureauSeulement ? 'bureau:flex hidden' : 'flex']"
                        >
                            <span
                                class="bg-surface-sunken text-accent-primary-deep flex size-9 shrink-0 items-center justify-center rounded-xl"
                            >
                                <GlassIcon :name="item.icon" size="sm" />
                            </span>
                            <span class="text-text-main flex-1 text-lg font-semibold">{{ item.name }}</span>
                            <GlassIcon name="chevron_right" class="text-text-muted" />
                        </Link>
                    </GlassCard>

                    <IndisponibleHorsLigne
                        v-if="rang === 0 && surUneCopie"
                        texte="Habitudes, journal, compléments et paramètres reviennent avec le réseau."
                    />

                    <!-- Un bouton : la déconnexion détache d'abord l'appareil du compte (#1926). -->
                    <button
                        v-if="rang === groupes.length - 1"
                        type="button"
                        class="verre-regulier text-accent-danger-deep rounded-capsule focus-visible:ring-accent-primary flex min-h-14 w-full items-center justify-center text-base font-semibold focus-visible:ring-2 focus-visible:outline-none"
                        data-testid="logout-button"
                        @click="seDeconnecter"
                    >
                        Déconnexion
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
