<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
})

const classes = computed(() =>
    props.active
        ? 'block w-full rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-start text-base font-black uppercase tracking-wide text-electric-orange shadow-glow-orange backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-electric-orange focus-visible:outline-none'
        : 'block w-full rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-start text-base font-bold text-text-muted backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:text-text-main hover:shadow-lg focus-visible:ring-2 focus-visible:ring-electric-orange focus-visible:outline-none',
)
</script>

<template>
    <Link v-press :href="href" :class="classes">
        <slot />
    </Link>
</template>
