<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import { Link } from '@inertiajs/vue3'
</script>

<template>
    <div class="flex min-h-[100dvh] min-h-screen flex-col items-center justify-center px-6 py-12">
        <!-- Logo -->
        <div class="mb-8 animate-fade-in">
            <Link href="/">
                <span class="text-gradient text-3xl font-bold">GymTracker</span>
            </Link>
        </div>

        <!-- Glass Card -->
        <div class="w-full max-w-md animate-slide-up">
            <div class="glass-card-strong p-8">
                <slot />
            </div>
        </div>

        <!-- Footer links -->
        <div class="mt-8 animate-fade-in text-center text-sm text-white/50">
            <slot name="footer" />
        </div>
    </div>
</template>
