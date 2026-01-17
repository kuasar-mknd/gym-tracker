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
                    :href="backRoute.startsWith('http') || backRoute.startsWith('/') ? backRoute : route(backRoute)"
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-text-muted shadow-sm transition-colors hover:text-electric-orange"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h1 class="font-display text-xl font-black uppercase italic tracking-tight text-text-main">
                    {{ title }}
                </h1>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
