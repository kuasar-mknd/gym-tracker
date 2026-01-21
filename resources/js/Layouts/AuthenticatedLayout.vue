<script setup>
import { ref } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import NavLink from '@/Components/NavLink.vue'
import { Link } from '@inertiajs/vue3'

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
})

const showingNavigationDropdown = ref(false)
</script>

<template>
    <div class="relative min-h-dvh w-full overflow-x-hidden">
        <!-- Liquid Glass Background -->
        <LiquidBackground :variant="liquidVariant" />

        <!-- Desktop Navigation -->
        <nav class="bg-pearl-white/80 sticky top-0 z-40 hidden border-b border-white/40 backdrop-blur-xl sm:block">
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
                            class="text-text-muted hover:text-electric-orange relative flex h-10 w-10 items-center justify-center rounded-xl border border-white bg-white/60 transition-all hover:bg-white hover:shadow-md"
                        >
                            <span class="material-symbols-outlined text-[22px]">notifications</span>
                            <span
                                v-if="$page.props.auth.user.unread_notifications_count > 0"
                                class="bg-electric-orange shadow-glow-orange absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-black text-white"
                            >
                                {{ $page.props.auth.user.unread_notifications_count }}
                            </span>
                        </Link>

                        <!-- User Dropdown -->
                        <div class="relative">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="text-text-main inline-flex items-center gap-2 rounded-xl border border-white bg-white/60 px-4 py-2 text-sm font-bold transition-all hover:bg-white hover:shadow-md"
                                    >
                                        <div
                                            class="bg-gradient-main flex h-7 w-7 items-center justify-center rounded-full text-xs font-black text-white"
                                        >
                                            {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                                        </div>
                                        {{ $page.props.auth.user.name }}
                                        <span class="material-symbols-outlined text-text-muted text-lg"
                                            >expand_more</span
                                        >
                                    </button>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        <span class="material-symbols-outlined mr-2 text-lg">person</span>
                                        Profil
                                    </DropdownLink>
                                    <DropdownLink :href="route('achievements.index')">
                                        <span class="material-symbols-outlined mr-2 text-lg">emoji_events</span>
                                        Trophées
                                    </DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        <span class="material-symbols-outlined mr-2 text-lg">logout</span>
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
            class="bg-pearl-white/80 sticky top-0 z-30 flex items-center justify-between border-b border-white/40 px-5 py-4 backdrop-blur-xl sm:hidden"
            :style="{ paddingTop: 'calc(1rem + var(--safe-area-top))' }"
        >
            <div class="flex min-w-0 items-center gap-4">
                <Link
                    v-if="showBack"
                    :href="backRoute ? route(backRoute) : 'javascript:history.back()'"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h1
                    v-if="pageTitle"
                    class="font-display text-text-main truncate text-2xl font-black tracking-tight uppercase italic"
                >
                    {{ pageTitle }}
                </h1>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    :href="route('notifications.index')"
                    class="text-text-muted relative flex h-10 w-10 items-center justify-center rounded-xl border border-white bg-white/60 transition-all active:scale-95"
                >
                    <span class="material-symbols-outlined text-[22px]">notifications</span>
                    <span
                        v-if="$page.props.auth.user.unread_notifications_count > 0"
                        class="bg-electric-orange absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-black text-white"
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
            class="bg-pearl-white/50 backdrop-blur-glass hidden border-b border-white/40 sm:block"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main
            class="relative z-10 px-5 py-6 sm:px-6 lg:px-8"
            :class="[{ 'pt-main-safe sm:pt-main-safe': !pageTitle && !showBack }, 'pb-main-safe']"
        >
            <div class="mx-auto max-w-7xl">
                <slot />
            </div>
        </main>

        <!-- Bottom Navigation (mobile only) -->
        <BottomNav class="sm:hidden" />

        <!-- Achievement Celebration Modal -->
        <CelebrationModal />
    </div>
</template>
