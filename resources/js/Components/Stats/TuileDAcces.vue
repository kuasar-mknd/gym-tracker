<script setup>
import { Link } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'

defineProps({
    href: {
        type: String,
        required: true,
    },
    icon: {
        type: String,
        required: true,
    },
    libelle: {
        type: String,
        required: true,
    },
    pleineLargeur: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <Link v-press :href="href" :class="['block', { 'col-span-2': pleineLargeur }]">
        <GlassCard padding="p-3" class="flex min-h-22 flex-col items-center justify-center gap-1 text-center">
            <GlassIcon :name="icon" class="text-accent-primary-deep" />
            <span class="titre-plat">{{ libelle }}</span>
        </GlassCard>
    </Link>
</template>
