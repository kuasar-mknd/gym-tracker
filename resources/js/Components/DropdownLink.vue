<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    href: {
        type: String,
        required: true,
    },
})
</script>

<template>
    <Link
        :href="href"
        class="text-text-muted hover:text-electric-orange focus:text-electric-orange mx-2 my-1 block rounded-xl border border-transparent px-4 py-2.5 text-start text-sm leading-5 font-medium backdrop-blur-md transition-all duration-200 hover:translate-x-1 hover:border-white/20 hover:bg-white/10 hover:shadow-[0_0_15px_rgba(255,255,255,0.1)] focus:border-white/20 focus:bg-white/10 focus:outline-none active:scale-95 dark:text-gray-300 dark:hover:text-white"
    >
        <slot />
    </Link>
</template>
