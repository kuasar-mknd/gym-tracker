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
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h1 class="font-display text-text-main text-xl font-black tracking-tight uppercase italic">
                    {{ title }}
                </h1>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </header>
</template>
