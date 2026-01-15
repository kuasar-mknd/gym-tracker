<script setup>
import { ref } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import CelebrationModal from '@/Components/Achievements/CelebrationModal.vue'
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
})

const showingNavigationDropdown = ref(false)
</script>

<template>
    <div class="min-h-[100dvh] min-h-screen pb-nav">
        <!-- Desktop Navigation (hidden on mobile) -->
        <nav class="hidden border-b border-glass-border bg-dark-700/80 backdrop-blur-glass sm:block">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex shrink-0 items-center">
                            <Link :href="route('dashboard')" class="text-gradient text-xl font-bold"> GymTracker </Link>
                        </div>

                        <!-- Navigation Links -->
                        <div class="hidden space-x-6 sm:ms-10 sm:flex">
                            <Link
                                :href="route('dashboard')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('dashboard')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Accueil
                            </Link>
                            <Link
                                :href="route('workouts.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('workouts.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Séances
                            </Link>
                            <Link
                                :href="route('body-measurements.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('body-measurements.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Corps
                            </Link>
                            <Link
                                :href="route('stats.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('stats.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Stats
                            </Link>
                            <Link
                                :href="route('daily-journals.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('daily-journals.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Journal
                            </Link>
                            <Link
                                :href="route('goals.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('goals.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Objectifs
                            </Link>
                            <Link
                                :href="route('achievements.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('achievements.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Trophées
                            </Link>
                            <Link
                                :href="route('plates.index')"
                                :class="[
                                    'inline-flex items-center px-1 pt-1 text-sm font-medium transition',
                                    route().current('plates.*')
                                        ? 'border-b-2 border-accent-primary text-accent-primary'
                                        : 'text-white/70 hover:text-white',
                                ]"
                            >
                                Calculateurs
                            </Link>
                        </div>
                    </div>

                    <div class="hidden gap-4 sm:ms-6 sm:flex sm:items-center">
                        <!-- Notification Bell (Desktop) -->
                        <Link
                            :href="route('notifications.index')"
                            class="relative rounded-xl border border-glass-border bg-glass p-2 text-white/80 transition hover:bg-glass-strong hover:text-white"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                                />
                            </svg>
                            <span
                                v-if="$page.props.auth.user.unread_notifications_count > 0"
                                class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-accent-primary text-[10px] font-bold text-white shadow-lg"
                            >
                                {{ $page.props.auth.user.unread_notifications_count }}
                            </span>
                        </Link>

                        <!-- Settings Dropdown -->
                        <div class="relative ms-3">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <span class="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-xl border border-glass-border bg-glass px-3 py-2 text-sm font-medium text-white/80 transition hover:bg-glass-strong hover:text-white"
                                        >
                                            {{ $page.props.auth.user.name }}
                                            <svg
                                                class="-me-0.5 ms-2 h-4 w-4"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                            >
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </span>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')"> Profil </DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        Déconnexion
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Mobile Header (shown on mobile only) -->
        <PageHeader v-if="pageTitle" :title="pageTitle" :show-back="showBack" :back-route="backRoute" class="sm:hidden">
            <template #actions>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('notifications.index')"
                        class="relative flex h-11 w-11 items-center justify-center rounded-xl bg-glass text-white/80 transition active:scale-95"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                            />
                        </svg>
                        <span
                            v-if="$page.props.auth.user.unread_notifications_count > 0"
                            class="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full border-2 border-[#0f172a] bg-accent-primary text-[10px] font-bold text-white shadow-lg"
                        >
                            {{ $page.props.auth.user.unread_notifications_count }}
                        </span>
                    </Link>
                    <slot name="header-actions" />
                </div>
            </template>
        </PageHeader>

        <!-- Page Heading (desktop) -->
        <header
            v-if="$slots.header"
            class="hidden border-b border-glass-border bg-dark-800/50 backdrop-blur-glass sm:block"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <slot />
            </div>
        </main>

        <!-- Bottom Navigation (mobile only) -->
        <BottomNav class="sm:hidden" />

        <CelebrationModal />
    </div>
</template>
