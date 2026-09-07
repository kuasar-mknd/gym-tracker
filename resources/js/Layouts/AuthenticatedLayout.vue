<script setup>
import { watch, onUnmounted } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'
import Dropdown from '@/Components/UI/Dropdown.vue'
import DropdownLink from '@/Components/UI/DropdownLink.vue'
import NavLink from '@/Components/Navigation/NavLink.vue'
import ActiveWorkoutBanner from '@/Components/Dashboard/ActiveWorkoutBanner.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { useRaccourciClavier } from '@/composables/useRaccourciClavier'

defineProps({
    pageTitle: {
        type: String,
        default: '',
    },
    showBack: {
        type: Boolean,
        default: false,
    },
    backRoute: {
        type: String,
        default: '',
    },
    liquidVariant: {
        type: String,
        default: 'default',
    },
    /**
     * La largeur du contenu sur grand écran.
     *
     * `large` (1280 px) convient à une grille de cartes. Un outil, lui, garde
     * une colonne de formulaire : étirée sur 1 216 px, elle donne des champs
     * d'un mètre de large et des tuiles de choix vides aux trois quarts
     * (#1802, #1817).
     */
    largeur: {
        type: String,
        default: 'large',
        validator: (valeur) => ['large', 'moyenne', 'etroite'].includes(valeur),
    },
})

const LARGEURS = { large: 'max-w-7xl', moyenne: 'max-w-3xl', etroite: 'max-w-2xl' }

// `?` ouvre la liste des raccourcis, d'où qu'on soit — le seul moyen de la
// découvrir sans passer par « Plus ».
useRaccourciClavier(
    (evenement) => evenement.key === '?',
    () => router.visit(route('shortcuts.index')),
)

const page = usePage()

const toasts = {
    success: { icon: 'check_circle', color: 'emerald', delay: 5000, id: null },
    error: { icon: 'error', color: 'red', delay: 8000, id: null },
}

/*
 * Chaque toast garde l'échéance posée à son apparition.
 *
 * Le watch se déclenche dès que l'une ou l'autre valeur bouge, et il ré-armait
 * alors tout toast encore affiché — pas seulement celui qui venait de changer.
 * Une erreur montrée en même temps qu'un succès voyait donc son compte à
 * rebours redémarrer quand le succès disparaissait à 5 s : elle restait 13 s au
 * lieu des 8 s configurées.
 *
 * Les deux délais sont réglés différemment à dessein — une erreur mérite d'être
 * lue plus longtemps. Le ré-armement rendait ce réglage imprévisible, puisque la
 * durée réelle dépendait de ce qui arrivait ensuite plutôt que de la nature du
 * message (#1387).
 *
 * Comparer à la valeur précédente suffit : un message réémis à l'identique passe
 * d'abord par null, quand son propre minuteur le retire, donc la transition est
 * bien vue et le minuteur bien reposé.
 */
watch(
    () => [page.props.flash?.success, page.props.flash?.error],
    (current, previous = []) => {
        current.forEach((value, i) => {
            const key = Object.keys(toasts)[i]

            if (!value || value === previous[i]) {
                return
            }

            clearTimeout(toasts[key].id)
            toasts[key].id = setTimeout(() => page.props.flash && (page.props.flash[key] = null), toasts[key].delay)
        })
    },
    { immediate: true },
)

const activeWorkout = computed(() => page.props.auth?.user?.active_workout)
const isWorkoutShow = computed(() => route().current('workouts.show'))
// La grande bannière n'a sa place que là où la séance est le sujet ; ailleurs, une ligne.
const banniereCompacte = computed(() => !route().current('dashboard') && !route().current('workouts.index'))

onUnmounted(() => Object.values(toasts).forEach((t) => clearTimeout(t.id)))
</script>

<template>
    <div class="bg-surface-page relative min-h-dvh w-full">
        <a
            href="#main-content"
            class="accent-fill z-evitement absolute top-0 left-0 -translate-y-full rounded-br-xl px-4 py-2 font-bold transition-transform focus:translate-y-0 focus:ring-2 focus:outline-none"
        >
            Aller au contenu principal
        </a>

        <LiquidBackground :variant="liquidVariant" />

        <!--
            Local only, and deliberately not dismissible: a database behind the
            code fails at the write, never at the read, so the app looks healthy
            while every save is refused. That is exactly how an afternoon was
            lost to a column added days earlier — and the test suite cannot warn
            about it, since it migrates a fresh database on every run.
        -->
        <div
            v-if="$page.props.pending_migrations > 0"
            class="bg-accent-warning text-text-main z-alerte sticky top-0 px-4 py-2 text-center text-sm font-bold"
            role="alert"
            data-testid="pending-migrations-banner"
        >
            {{ $page.props.pending_migrations }} migration(s) en attente — lance
            <code class="bg-text-main/15 rounded px-1">sail artisan migrate</code>
            avant de continuer, sinon les enregistrements échoueront sans le dire.
        </div>

        <!-- Flash Toasts -->
        <div v-for="(cfg, type) in toasts" :key="type">
            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 -translate-y-4"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-4"
            >
                <div
                    v-if="$page.props.flash?.[type]"
                    class="z-toast fixed top-20 right-4 left-4 sm:right-6 sm:left-auto sm:w-80"
                    role="alert"
                    :aria-live="type === 'error' ? 'assertive' : 'polite'"
                >
                    <div
                        :class="[
                            'glass-panel-light flex items-center gap-3 rounded-2xl border-l-[6px] p-4 shadow-lg backdrop-blur-xl',
                            type === 'success' ? 'border-l-accent-state' : 'border-l-accent-danger',
                        ]"
                    >
                        <div
                            :class="[
                                'flex size-10 items-center justify-center rounded-xl',
                                type === 'success'
                                    ? 'bg-accent-state/10 text-accent-state-deep'
                                    : 'bg-accent-danger/10 text-accent-danger-deep',
                            ]"
                        >
                            <GlassIcon :name="cfg.icon" />
                        </div>
                        <p class="text-text-main flex-1 text-sm font-bold">
                            {{ $page.props.flash[type] }}
                        </p>
                        <GlassIconButton
                            v-press
                            icon="close"
                            label="Fermer le message"
                            @click="$page.props.flash[type] = null"
                        />
                    </div>
                </div>
            </Transition>
        </div>

        <!-- Desktop Navigation -->
        <nav
            class="bg-surface-page/80 border-surface-card/40 z-nav sticky top-0 hidden border-b backdrop-blur-xl sm:block"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex shrink-0 items-center">
                            <Link
                                :href="route('dashboard')"
                                class="text-gradient font-display text-2xl font-black tracking-tight uppercase italic"
                            >
                                GymTracker
                            </Link>
                        </div>

                        <!-- Desktop Navigation Links -->
                        <div class="hidden space-x-1 sm:ms-8 sm:flex">
                            <NavLink :href="route('dashboard')" :active="route().current('dashboard')">
                                Accueil
                            </NavLink>
                            <NavLink :href="route('workouts.index')" :active="route().current('workouts.*')">
                                Séances
                            </NavLink>
                            <NavLink :href="route('calendar.index')" :active="route().current('calendar.*')">
                                Calendrier
                            </NavLink>
                            <NavLink :href="route('stats.index')" :active="route().current('stats.*')"> Stats </NavLink>
                            <NavLink :href="route('exercises.index')" :active="route().current('exercises.*')">
                                Exercices
                            </NavLink>
                            <NavLink
                                :href="route('tools.index')"
                                :active="route().current('tools.*') || route().current('plates.*')"
                            >
                                Outils
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden gap-3 sm:ms-6 sm:flex sm:items-center">
                        <!-- Notification Bell -->
                        <Link
                            :href="route('notifications.index')"
                            class="text-text-muted hover:text-accent-primary-deep border-surface-card bg-surface-card/60 hover:bg-surface-card relative flex size-11 shrink-0 items-center justify-center rounded-xl border transition hover:shadow-md"
                            :aria-label="
                                $page.props.auth.user.unread_notifications_count > 0
                                    ? `Notifications (${$page.props.auth.user.unread_notifications_count} non lues)`
                                    : 'Notifications'
                            "
                        >
                            <GlassIcon name="notifications" />
                            <span
                                v-if="$page.props.auth.user.unread_notifications_count > 0"
                                class="accent-fill text-2xs absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full font-black"
                            >
                                {{ $page.props.auth.user.unread_notifications_count }}
                            </span>
                        </Link>

                        <!-- User Dropdown -->
                        <div class="relative">
                            <Dropdown align="right" width="48">
                                <template #trigger="{ open }">
                                    <button
                                        type="button"
                                        aria-haspopup="true"
                                        :aria-expanded="open"
                                        class="text-text-main focus-visible:ring-accent-primary border-border bg-surface-card hover:bg-surface-card inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-bold shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                    >
                                        <div
                                            class="bg-gradient-main text-text-on-dark-accent flex h-7 w-7 items-center justify-center rounded-full text-xs font-black"
                                        >
                                            {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                                        </div>
                                        {{ $page.props.auth.user.name }}
                                        <GlassIcon name="expand_more" size="sm" class="text-text-muted" />
                                    </button>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.index')">
                                        <GlassIcon name="person" size="sm" class="mr-2" />
                                        Profil
                                    </DropdownLink>
                                    <DropdownLink :href="route('profile.edit')">
                                        <GlassIcon name="settings" size="sm" class="mr-2" />
                                        Paramètres
                                    </DropdownLink>
                                    <DropdownLink :href="route('achievements.index')">
                                        <GlassIcon name="emoji_events" size="sm" class="mr-2" />
                                        Trophées
                                    </DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        <GlassIcon name="logout" size="sm" class="mr-2" />
                                        Déconnexion
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Mobile Header -->
        <header
            v-if="pageTitle || showBack"
            class="bg-surface-page/80 border-surface-card/40 z-collant sticky top-0 flex items-center justify-between border-b px-5 py-4 backdrop-blur-xl sm:hidden"
            :style="{ paddingTop: 'calc(1rem + var(--safe-area-top))' }"
        >
            <div class="flex min-w-0 items-center gap-4">
                <Link
                    v-if="showBack"
                    v-press
                    :href="backRoute ? route(backRoute) : 'javascript:history.back()'"
                    class="text-text-muted hover:text-accent-primary-deep border-border bg-surface-card flex h-10 w-10 shrink-0 items-center justify-center rounded-full border shadow-sm transition-colors"
                    aria-label="Retour"
                >
                    <GlassIcon name="arrow_back" />
                </Link>
                <h1
                    v-if="pageTitle"
                    class="font-display text-text-main truncate font-black tracking-tight uppercase italic"
                    :class="pageTitle.length > 12 ? 'text-xl' : 'text-2xl'"
                >
                    {{ pageTitle }}
                </h1>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    v-press
                    :href="route('notifications.index')"
                    class="text-text-muted border-surface-card bg-surface-card/60 relative flex size-11 shrink-0 items-center justify-center rounded-xl border transition"
                    :aria-label="
                        $page.props.auth.user.unread_notifications_count > 0
                            ? `Notifications (${$page.props.auth.user.unread_notifications_count} non lues)`
                            : 'Notifications'
                    "
                >
                    <GlassIcon name="notifications" />
                    <span
                        v-if="$page.props.auth.user.unread_notifications_count > 0"
                        class="accent-fill text-2xs absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full font-black"
                    >
                        {{ $page.props.auth.user.unread_notifications_count }}
                    </span>
                </Link>
                <slot name="header-actions" />
            </div>
        </header>

        <!-- Desktop Page Heading Slot -->
        <header
            v-if="$slots.header"
            class="bg-surface-page/50 backdrop-blur-glass border-surface-card/40 hidden border-b sm:block"
        >
            <div class="mx-auto px-4 py-6 sm:px-6 lg:px-8" :class="LARGEURS[largeur]">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main
            id="main-content"
            class="relative z-10 px-5 py-6 sm:px-6 lg:px-8"
            :class="[{ 'pt-main-safe sm:pt-main-safe': !pageTitle && !showBack }, 'pb-main-safe']"
        >
            <div v-if="activeWorkout && !isWorkoutShow" class="mx-auto mb-6" :class="LARGEURS[largeur]">
                <ActiveWorkoutBanner :workout="activeWorkout" :compact="banniereCompacte" />
            </div>

            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-4"
                mode="out-in"
            >
                <div :key="$page.url" class="mx-auto" :class="LARGEURS[largeur]">
                    <slot />
                </div>
            </Transition>
        </main>

        <!-- Bottom Navigation (mobile only) -->
        <BottomNav class="sm:hidden" />

        <!-- Achievement Celebration Modal -->
        <CelebrationModal />
    </div>
</template>
