<script setup>
import { ref } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
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
    <div class="relative min-h-[100dvh] min-h-screen">
        <!-- Liquid Glass Background -->
        <LiquidBackground :variant="liquidVariant" />

        <!-- Desktop Navigation -->
        <nav class="sticky top-0 z-40 hidden border-b border-white/40 bg-pearl-white/80 backdrop-blur-xl sm:block">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex shrink-0 items-center">
                            <Link
                                :href="route('dashboard')"
                                class="text-gradient font-display text-2xl font-black uppercase italic tracking-tight"
                            >
                                GymTracker
                            </Link>
                        </div>

                        <!-- Desktop Navigation Links -->
                        <div class="hidden space-x-1 sm:ms-8 sm:flex">
                            <Link
                                :href="route('dashboard')"
                                :class="[
                                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-bold uppercase tracking-wide transition-all',
                                    route().current('dashboard')
                                        ? 'bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted hover:bg-white/50 hover:text-text-main',
                                ]"
                            >
                                Accueil
                            </Link>
                            <Link
                                :href="route('workouts.index')"
                                :class="[
                                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-bold uppercase tracking-wide transition-all',
                                    route().current('workouts.*')
                                        ? 'bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted hover:bg-white/50 hover:text-text-main',
                                ]"
                            >
                                Séances
                            </Link>
                            <Link
                                :href="route('stats.index')"
                                :class="[
                                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-bold uppercase tracking-wide transition-all',
                                    route().current('stats.*')
                                        ? 'bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted hover:bg-white/50 hover:text-text-main',
                                ]"
                            >
                                Stats
                            </Link>
                            <Link
                                :href="route('exercises.index')"
                                :class="[
                                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-bold uppercase tracking-wide transition-all',
                                    route().current('exercises.*')
                                        ? 'bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted hover:bg-white/50 hover:text-text-main',
                                ]"
                            >
                                Exercices
                            </Link>
                            <Link
                                :href="route('tools.index')"
                                :class="[
                                    'inline-flex items-center rounded-lg px-3 py-2 text-sm font-bold uppercase tracking-wide transition-all',
                                    route().current('tools.*') || route().current('plates.*')
                                        ? 'bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted hover:bg-white/50 hover:text-text-main',
                                ]"
                            >
                                Outils
                            </Link>
                        </div>
                    </div>

                    <div class="hidden gap-3 sm:ms-6 sm:flex sm:items-center">
                        <!-- Notification Bell -->
                        <Link
                            :href="route('notifications.index')"
                            class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-white bg-white/60 text-text-muted transition-all hover:bg-white hover:text-electric-orange hover:shadow-md"
                        >
                            <span class="material-symbols-outlined text-[22px]">notifications</span>
                            <span
                                v-if="$page.props.auth.user.unread_notifications_count > 0"
                                class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-electric-orange text-[10px] font-black text-white shadow-glow-orange"
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
                                        class="inline-flex items-center gap-2 rounded-xl border border-white bg-white/60 px-4 py-2 text-sm font-bold text-text-main transition-all hover:bg-white hover:shadow-md"
                                    >
                                        <div
                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-main text-xs font-black text-white"
                                        >
                                            {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                                        </div>
                                        {{ $page.props.auth.user.name }}
                                        <span class="material-symbols-outlined text-lg text-text-muted"
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
            class="sticky top-0 z-30 flex items-center justify-between border-b border-white/40 bg-pearl-white/80 px-5 py-4 pt-safe backdrop-blur-xl sm:hidden"
        >
            <div class="flex items-center gap-4">
                <Link
                    v-if="showBack"
                    :href="backRoute ? route(backRoute) : 'javascript:history.back()'"
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-text-muted shadow-sm transition-colors hover:text-electric-orange"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h1
                    v-if="pageTitle"
                    class="font-display text-2xl font-black uppercase italic tracking-tight text-text-main"
                >
                    {{ pageTitle }}
                </h1>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    :href="route('notifications.index')"
                    class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-white bg-white/60 text-text-muted transition-all active:scale-95"
                >
                    <span class="material-symbols-outlined text-[22px]">notifications</span>
                    <span
                        v-if="$page.props.auth.user.unread_notifications_count > 0"
                        class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-electric-orange text-[10px] font-black text-white"
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
            class="hidden border-b border-white/40 bg-pearl-white/50 backdrop-blur-glass sm:block"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main class="relative z-10 px-5 py-6 pb-36 sm:px-6 lg:px-8">
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
