<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    title: {
        type: String,
        default: '',
    },
    backRoute: {
        type: String,
        default: '',
    },
    showBack: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <header class="glass-header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link
                    v-if="showBack && backRoute"
                    :href="route(backRoute)"
                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 transition hover:bg-white/20"
                >
                    <svg
                        class="h-5 w-5"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-lg font-bold text-white">{{ title }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
