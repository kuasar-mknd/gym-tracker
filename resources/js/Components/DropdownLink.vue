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
        class="block mx-2 my-1 rounded-xl border border-transparent px-4 py-2.5 text-start text-sm font-medium leading-5 text-text-muted backdrop-blur-md transition-all duration-200 hover:border-white/20 hover:bg-white/10 hover:text-electric-orange hover:translate-x-1 hover:shadow-[0_0_15px_rgba(255,255,255,0.1)] focus:border-white/20 focus:bg-white/10 focus:text-electric-orange focus:outline-none active:scale-95 dark:text-gray-300 dark:hover:text-white"
    >
        <slot />
    </Link>
</template>
