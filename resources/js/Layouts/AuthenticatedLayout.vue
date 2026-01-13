<script setup>
import { ref } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
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
                        </div>
                    </div>

                    <div class="hidden sm:ms-6 sm:flex sm:items-center">
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
                                    <div class="overflow-hidden rounded-xl border border-glass-border bg-dark-700">
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                            class="text-white/80 hover:bg-glass"
                                        >
                                            Profil
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                            class="text-white/80 hover:bg-glass"
                                        >
                                            Déconnexion
                                        </DropdownLink>
                                    </div>
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
                <slot name="header-actions" />
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
    </div>
</template>
