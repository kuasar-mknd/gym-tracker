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
        v-press
        :href="href"
        class="mx-2 my-1 block w-full rounded-2xl border border-white/20 bg-white/10 px-4 py-2.5 text-start text-sm font-medium text-white/80 backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:text-white focus:bg-white/20 focus:outline-none"
    >
        <slot />
    </Link>
</template>
